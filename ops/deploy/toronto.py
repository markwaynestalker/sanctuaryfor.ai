#!/usr/bin/env python3
"""Owner-installed, unprivileged Toronto release switcher. Python stdlib only.

SSH is restricted to `deploy <40-hex-main-SHA>`. Local prepare/activate-reviewed/rollback/recover
are owner operations. No SQL, credentials, service commands or repository code
execution (only PHP syntax checks) occurs here. See docs/TORONTO_DEPLOYMENT.md.
"""
import contextlib
import dataclasses
import datetime
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.request

try:
    import fcntl
except ImportError:  # Windows can run portable unit tests, never the real CLI.
    fcntl = None

REMOTE = "https://github.com/markwaynestalker/sanctuaryfor.ai.git"
SHA = re.compile(r"[0-9a-f]{40}\Z")
ROOT = Path("/var/lib/sanctuaryfor-ai-deploy")
LIVE = Path("/var/www/sanctuaryfor.ai")
HEALTH = "https://sanctuaryfor.ai/health.php"
MAX_BYTES, MAX_FILES = 64 * 1024 * 1024, 20000
ENV = {"PATH": "/usr/bin:/bin", "LC_ALL": "C.UTF-8", "GIT_TERMINAL_PROMPT": "0",
       "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null",
       "OPENAI_API_KEY": "", "LITTLE_SPARKS_AI_ENABLED": "0",
       "LITTLE_SPARKS_DIRECTOR_ENABLED": "0", "LITTLE_SPARKS_SIMULATION_ENABLED": "0"}


class Refused(Exception):
    def __init__(self, code, exit_code=20):
        super().__init__(code)
        self.code, self.exit_code = code, exit_code


def commit(value):
    if not isinstance(value, str) or not SHA.fullmatch(value):
        raise Refused("invalid_commit", 10)
    return value


def command(arguments, ssh_command=None):
    if ssh_command is not None:
        match = re.fullmatch(r"deploy ([0-9a-f]{40})", ssh_command)
        if not match:
            raise Refused("restricted_ssh_command", 10)
        return "deploy", match[1]
    if arguments == ["recover"]:
        return "recover", None
    if len(arguments) != 2 or arguments[0] not in ("prepare", "deploy", "activate-reviewed", "rollback"):
        raise Refused("usage_prepare_deploy_activate-reviewed_rollback_SHA_or_recover", 10)
    return arguments[0], commit(arguments[1])


def safe_path(name):
    path = PurePosixPath(name)
    if (not name or name != path.as_posix() or path.is_absolute()
            or any(p in ("..", ".git", "tmp", "cache", "data") for p in path.parts)
            or any(ord(c) < 32 or c == "\\" for c in name)
            or path.name == ".release-commit"
            or (path.name.startswith(".env") and path.name != ".env.example")
            or re.search(r"\.(?:key|pem|sqlite(?:3)?(?:-.*)?|db(?:-.*)?)$", name, re.I)):
        raise Refused("unsafe_release_path")
    return path


def operational_change(name):
    path = PurePosixPath(name)
    return (name == ".env.example" or name == "scripts/register-director.php"
            or name.startswith(("ops/deploy/", "ops/systemd/"))
            or (name.startswith("app/") and path.name.endswith("schema.php"))
            or (name.startswith("scripts/") and path.name.startswith("migrate-")))


def write_new(path, data, mode=0o600):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    with os.fdopen(fd, "wb") as output:
        output.write(data)
        output.flush()
        # The caller uses umask 077; final public code must still be readable by FPM.
        if os.name == 'posix':
            os.fchmod(output.fileno(), mode)
        os.fsync(output.fileno())


def sync_directory(path):
    if os.name == "posix":
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)


@dataclasses.dataclass(frozen=True)
class Settings:
    root: Path = ROOT
    live: Path = LIVE
    remote: str = REMOTE
    health: str = HEALTH
    git: str = "/usr/bin/git"
    php: str = "/usr/bin/php8.3"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Deployer:
    def __init__(self, settings=Settings()):
        self.cfg = settings
        self.root = settings.root
        self.private = self.root / "private"
        self.repo = self.private / "repository.git"
        self.releases = self.root / "releases"
        self.current = self.root / "current"
        self.pending = self.private / "transaction.json"

    def git(self, *args):
        try:
            result = subprocess.run([self.cfg.git, "-c", "core.hooksPath=/dev/null",
                                     "--git-dir=" + str(self.repo), *args],
                                    env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    timeout=90, check=True)
            return result.stdout
        except (subprocess.SubprocessError, OSError):
            raise Refused("git_operation_failed", 22) from None

    def validate_installation(self):
        if os.name != "posix" or os.geteuid() == 0:
            raise Refused("requires_unprivileged_linux_deploy_account", 10)
        for path, mode in [(self.root, 0o755), (self.private, 0o700), (self.releases, 0o755)]:
            s = path.lstat()
            if path.resolve() != path or not stat.S_ISDIR(s.st_mode) or s.st_uid != os.geteuid() or stat.S_IMODE(s.st_mode) != mode:
                raise Refused("unsafe_deploy_directory", 10)
        if self.repo.is_symlink() or self.repo.resolve().parent != self.private:
            raise Refused("unsafe_repository_path", 10)
        if self.git("rev-parse", "--is-bare-repository").strip() != b"true" or self.git("config", "--get", "remote.origin.url").decode().strip() != self.cfg.remote:
            raise Refused("repository_identity_mismatch", 10)

    @contextlib.contextmanager
    def lock(self):
        if fcntl is None:
            raise Refused("linux_lock_required", 10)
        flags = os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW
        fd = os.open(self.private / "deploy.lock", flags, 0o600)
        with os.fdopen(fd, "a+b") as handle:
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise Refused("deployment_busy", 30) from None
            yield

    def audit(self, state, target, previous=None):
        row = {"at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
               "state": state, "commit": target, "previous": previous}
        fd = os.open(self.private / "audit.jsonl", os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "ab") as output:
            output.write(json.dumps(row, separators=(",", ":")).encode() + b"\n")
            output.flush()
            os.fsync(output.fileno())

    def approved_main(self, target):
        self.git("fetch", "--no-tags", self.cfg.remote, "refs/heads/main:refs/remotes/origin/main")
        if self.git("rev-parse", "refs/remotes/origin/main").decode().strip() != target:
            raise Refused("target_is_not_current_main", 22)
        if self.git("cat-file", "-t", target).strip() != b"commit":
            raise Refused("target_is_not_commit", 22)

    def inventory(self, target):
        entries, total = {}, 0
        for row in self.git("ls-tree", "-rlz", "--full-tree", target).split(b"\0"):
            if not row:
                continue
            meta, raw_name = row.split(b"\t", 1)
            mode, kind, oid, size = meta.split()
            name = raw_name.decode("utf-8")
            safe_path(name)
            if kind != b"blob" or mode not in (b"100644", b"100755"):
                raise Refused("symlink_submodule_or_special_file")
            total += int(size)
            entries[name] = {"oid": oid.decode(), "size": int(size), "mode": 0o555 if mode == b"100755" else 0o444}
        if not entries or len(entries) > MAX_FILES or total > MAX_BYTES:
            raise Refused("release_size_limit")
        return entries

    def manifest_path(self, target):
        return self.private / (commit(target) + ".json")

    def check_release(self, target):
        release = self.releases / commit(target)
        if release.is_symlink() or release.resolve() != release or not release.is_dir():
            raise Refused("unsafe_release_directory")
        manifest_file = self.manifest_path(target)
        rs = release.stat()
        ms = manifest_file.lstat()
        if (stat.S_IMODE(rs.st_mode) != 0o555 or rs.st_uid != os.geteuid()
                or not stat.S_ISREG(ms.st_mode) or ms.st_uid != os.geteuid()
                or ms.st_nlink != 1 or stat.S_IMODE(ms.st_mode) != 0o600):
            raise Refused("unsafe_manifest")
        manifest = json.loads(manifest_file.read_text())
        if manifest.get("commit") != target:
            raise Refused("release_identity_mismatch")
        expected = manifest["files"]
        found = {}
        for path in release.rglob("*"):
            if path.is_symlink():
                raise Refused("unexpected_local_changes")
            s = path.stat()
            if s.st_uid != os.geteuid():
                raise Refused("unexpected_local_changes")
            if path.is_dir():
                if stat.S_IMODE(s.st_mode) != 0o555:
                    raise Refused("unexpected_local_changes")
                continue
            if not stat.S_ISREG(s.st_mode) or s.st_nlink != 1:
                raise Refused("unexpected_local_changes")
            name = path.relative_to(release).as_posix()
            found[name] = {"sha256": hashlib.sha256(path.read_bytes()).hexdigest(), "mode": stat.S_IMODE(s.st_mode)}
        if found != expected or (release / ".release-commit").read_text() != target + "\n":
            raise Refused("unexpected_local_changes")
        return release

    def current_commit(self):
        if not self.current.is_symlink():
            raise Refused("current_pointer_not_initialized")
        destination = Path(os.readlink(self.current))
        if destination.parent != self.releases or not SHA.fullmatch(destination.name):
            raise Refused("current_pointer_outside_releases")
        self.check_release(destination.name)
        return destination.name

    def ensure_code_only(self, previous, target):
        changed = self.git("diff", "--name-only", "-z", previous, target).decode().split("\0")
        if any(operational_change(name) for name in changed if name):
            raise Refused("separate_operational_review_required", 21)

    def stage(self, target):
        release = self.releases / target
        if release.exists() or release.is_symlink():
            return self.check_release(target)
        entries = self.inventory(target)
        archive = self.git("archive", "--format=tar", target)
        if len(archive) > MAX_BYTES + MAX_FILES * 2048:
            raise Refused("archive_size_limit")
        stage = Path(tempfile.mkdtemp(prefix=".stage-" + target + "-", dir=self.releases))
        files, started = {}, time.monotonic()
        # Manual extraction: never tar.extractall, follow symlinks, or execute hooks.
        with tarfile.open(fileobj=io.BytesIO(archive), mode="r:") as source:
            for member in source:
                name = member.name.rstrip("/") if member.isdir() else member.name
                safe_path(name)
                if member.isdir():
                    continue
                if not member.isfile() or name not in entries or name in files or member.size != entries[name]["size"]:
                    raise Refused("archive_tree_mismatch")
                data = source.extractfile(member).read()
                oid = hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()
                if oid != entries[name]["oid"]:
                    raise Refused("archive_blob_mismatch")
                path = stage / name
                path.parent.mkdir(parents=True, exist_ok=True)
                write_new(path, data, entries[name]["mode"])
                if path.suffix == ".php":
                    try:
                        subprocess.run([self.cfg.php, "-n", "-l", str(path)], env=ENV,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True, timeout=10)
                    except (subprocess.SubprocessError, OSError):
                        raise Refused("php_syntax_failed") from None
                if time.monotonic() - started > 180:
                    raise Refused("staging_deadline")
                files[name] = {"sha256": hashlib.sha256(data).hexdigest(), "mode": entries[name]["mode"]}
        if set(files) != set(entries) or "public/health.php" not in files:
            raise Refused("incomplete_release")
        marker = (target + "\n").encode()
        write_new(stage / ".release-commit", marker, 0o444)
        files[".release-commit"] = {"sha256": hashlib.sha256(marker).hexdigest(), "mode": 0o444}
        for path in sorted(stage.rglob("*"), key=lambda p: len(p.parts), reverse=True):
            if path.is_dir():
                path.chmod(0o555)
        stage.chmod(0o555)
        write_new(self.manifest_path(target), json.dumps({"commit": target, "files": files}, sort_keys=True).encode())
        os.rename(stage, release)
        sync_directory(self.releases)
        return self.check_release(target)

    def switch(self, target):
        temporary = self.root / (".current-" + os.urandom(8).hex())
        os.symlink(self.releases / commit(target), temporary)
        os.replace(temporary, self.current)
        sync_directory(self.root)

    def healthy(self, target):
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect)
        for attempt in range(25):
            try:
                request = urllib.request.Request(self.cfg.health + '?release=' + commit(target), headers={"Cache-Control": "no-cache", "User-Agent": "Sanctuary-release-check"})
                with opener.open(request, timeout=5) as response:
                    data = json.loads(response.read(2049))
                    if response.status == 200 and data == {"status": "ok", "commit": target}:
                        return True
            except (OSError, ValueError):
                pass
            if attempt < 24:
                time.sleep(5)
        return False

    def deploy(self, target, rollback=False, reviewed=False):
        if self.pending.exists():
            raise Refused("unfinished_deployment_use_owner_recovery", 31)
        previous = self.current_commit()
        if rollback:
            self.check_release(target)
        else:
            self.approved_main(target)
        if not reviewed:
            self.ensure_code_only(previous, target)
        self.stage(target)
        if previous == target:
            if not self.healthy(target):
                raise Refused("current_release_unhealthy", 40)
            return {"status": "already_current", "commit": target}
        write_new(self.pending, json.dumps({"previous": previous, "target": target}).encode())
        sync_directory(self.private)
        self.audit("switch_started", target, previous)
        try:
            self.switch(target)
            if not self.healthy(target):
                raise Refused("health_check_failed", 40)
            self.audit("rollback_completed" if rollback else "deployed", target, previous)
            self.pending.unlink()
            sync_directory(self.private)
        except BaseException:
            self.check_release(previous)
            self.switch(previous)
            if not self.healthy(previous):
                self.audit("recovery_required", target, previous)
                raise Refused("rollback_health_failed_owner_recovery_required", 41) from None
            self.audit("rolled_back_after_failure", target, previous)
            self.pending.unlink()
            sync_directory(self.private)
            raise Refused("deployment_failed_previous_release_restored", 40) from None
        return {"status": "rolled_back" if rollback else "deployed", "commit": target, "previous": previous}

    def recover(self):
        if not self.pending.exists() or self.pending.is_symlink():
            raise Refused("no_valid_recovery_journal", 31)
        journal = json.loads(self.pending.read_text())
        previous, target = commit(journal["previous"]), commit(journal["target"])
        if self.current_commit() not in (previous, target):
            raise Refused("recovery_pointer_mismatch", 31)
        self.check_release(previous)
        self.switch(previous)
        if not self.healthy(previous):
            raise Refused("recovery_health_failed", 41)
        self.audit("owner_recovered_previous", target, previous)
        self.pending.unlink()
        sync_directory(self.private)
        return {"status": "recovered", "commit": previous}


def main():
    os.umask(0o077)
    try:
        action, target = command(sys.argv[1:], os.environ.get("SSH_ORIGINAL_COMMAND"))
        deployer = Deployer()
        deployer.validate_installation()
        for sig in (signal.SIGTERM, signal.SIGHUP):
            signal.signal(sig, lambda *_: (_ for _ in ()).throw(Refused("interrupted", 45)))
        with deployer.lock():
            if action == "prepare":
                if deployer.pending.exists():
                    raise Refused("unfinished_deployment_use_owner_recovery", 31)
                deployer.approved_main(target)
                deployer.stage(target)
                result = {"status": "prepared_only", "commit": target}
            else:
                if not LIVE.is_symlink() or os.readlink(LIVE) != str(ROOT / "current") or LIVE.lstat().st_uid != 0:
                    raise Refused("owner_live_path_setup_required", 10)
                result = deployer.recover() if action == "recover" else deployer.deploy(target, action == "rollback", action == "activate-reviewed")
        print(json.dumps(result))
        return 0
    except Refused as error:
        print(json.dumps({"status": "refused", "code": error.code}))
        return error.exit_code
    except Exception:
        print(json.dumps({"status": "refused", "code": "local_validation_failed"}))
        return 20


if __name__ == "__main__":
    sys.exit(main())
