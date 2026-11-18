# HR daily door activity

The HR workforce page shows the latest 50 personnel events for the current day in the configured attendance timezone, newest first. It uses approved local names and public HPC employee numbers where an identity is linked, and the device-reported name otherwise. Granted, denied and informational results remain distinct. Direction is not inferred from a successful authentication.

The page refreshes every 10 seconds while visible. Collection runs every 15 seconds with the existing persistent checkpoints, deduplication, overlap locks and 10-second device-log safety window. This is near real time; device or network delays can increase latency. Each terminal shows its collection checkpoint and current, delayed or connection status. Offline terminals retain their checkpoint for recovery.

The feed is restricted by the same HR permission as the page, is not browser-cacheable, and never contacts hardware during the web request. Its bounded query eager-loads identities, terminals and doors. Browser rendering treats names as text, retains prior rows on refresh failure, prevents overlapping requests, and stops when access expires. Full history and PDF, Excel and Word exports remain available through the linked event log.

Local verification: HR authorization, day boundaries in Africa/Harare, future-event exclusion, newest-first limit, approved name/HPC display, denied results, freshness states and HTML escaping. Browser checks at 320, 390, 768 and 1440 pixels cover overflow, refresh, safe text rendering, retained data on failure and expired-session behavior.
