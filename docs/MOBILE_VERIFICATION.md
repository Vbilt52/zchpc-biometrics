# Mobile layout verification

Verified on 18 November 2026 using locally rendered Blade pages with fictional in-memory personnel, events and terminal records. No production credentials or personnel data were included in the browser fixtures.

- 37 pages checked in headless Chrome at viewport widths 320, 390, 768, 1024 and 1440 pixels: no document-level horizontal overflow.
- Coverage: overview and role dashboards; personnel list/create/detail/edit; access list/detail/edit/enrollment; all five onboarding steps; doors, matrix, sync and audit; devices/alerts/activity; terminal list/create/edit; events; attendance; account list/create/edit/password; access groups; super fingerprints; login.
- Checked drawer opening, Escape dismissal, focus return, inert background, fixed header during page scrolling, desktop collapse and navigation in 844 x 390 landscape.
- Checked stacked account fields, keyboard-focusable horizontally scrollable tables, and login/device dialogs in 568 x 320 landscape.
- Visually reviewed phone screenshots for dashboard, account creation and access logs.

Wide record tables intentionally scroll within their own region to retain all columns. Dashboard activity becomes a labelled vertical list on phones. Form text is at least 16px on phones to avoid automatic input zoom. Navigation uses an inline menu icon so it remains visible if the external icon font cannot load.

To repeat the review, use fictional fixture records and check the above routes at the listed widths, including long names, populated tables, menu controls, and enrollment steps. Keep print/export templates separate from responsive screen styles. Browser emulation does not replace a physical iOS/Android device check.
