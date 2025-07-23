# EVE Online Corp Alliance Stat WordPress Shortcodes
`[zkill_stats_members id="x"]`, `[zkill_stats_ships id="x"]`, and `[zkill_stats_isk id="x"]` to display member count, total ships destroyed and total ISK destroyed.

Replace `"x"` with the corporation `ID`
Combine values from more than one corp with` "x,y"`
Alliance stats with `"z" type="Alliance"`

**Examples**

Corp Applied Anarchy.

`[zkill_stats_members id="98328437"] [zkill_stats_ships id="98328437"] [zkill_stats_isk id="98328437"]`

Corp Institutional Anarchy.

`[zkill_stats_members id="98807196"] [zkill_stats_ships id="98807196"] [zkill_stats_isk id="98807196"]`

Corps Applied Anarchy and Institutional Anarchy combined.

`[zkill_stats_members id="98328437,98807196"] [zkill_stats_ships id="98328437,98807196"] [zkill_stats_isk id="98328437,98807196"]`

Alliance The Initiative.

`[zkill_stats_members id="1900696668" type="alliance"] [zkill_stats_ships id="1900696668" type="alliance"] [zkill_stats_isk id="1900696668" type="alliance"]`

Download the latest release, extract the folder, upload the `eve-corp-stat-shortcodes` folder to `wp-content/plugins`
