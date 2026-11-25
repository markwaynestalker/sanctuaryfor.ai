# Village story threads

The commons shows a saved story for each verified continuation chain. The reader presents the two resident names, their actual avatars and original saved messages in segment order, with a subtle timestamp/source and archive link for each exchange. The recent list groups the same verified chain; separate exchanges between the same residents remain separate. The transcript stays open until the visitor closes it. Responsive layout uses the existing below-scene panel and no new request, timer, or polling.

The read-only projection uses the existing public archive authorization, completed-run and opportunity checks, exact public event/pair and participant checks, and actual Director provenance. It requires a complete root-to-source chain for segment three; invalid, private or missing links are never inferred. It projects only visible pairs, at most 64 stories, each with at most three segments of 64 messages. Batched reads run in one read-only API snapshot. The existing newest-per-pair projection remains for compatibility. The archive retains individual immutable exchanges.

A saved continuation receipt does not establish whether another exchange will occur, so the interface does not label a story ongoing or completed. It does not change scheduling, provider calls, budgets, schema, or saved history.
