# Changelog

## 0.3.0 (unreleased)

- **Confidential visits, before a claim or a statement exposes them.** Under **Which visits count**, a new **Confidential services** setting names the visit categories you use for confidential services (none until you pick them). The Staff Worklist (0.8.0 or later) then has a **Confidential visits** tab for billing staff, with visits from the last year in those categories:
  - **queued or billed to insurance:** the policyholder may see the claim, so check the client was told and agreed (42 CFR 59.10(a)). A claim still queued is listed first, since it can still be taken back. The row never names a payer.
  - **a balance whose statement would go to someone else:** to the guardian on Demographics, as OpenEMR 8.5.0 and later address statements, or, before 8.5.0, when a guardian is on file. Hold that statement or bill the client directly (42 CFR 59.10).

  Each row is dismissed on its own, with a reason. `form_encounter.sensitivity` isn't used to decide what is confidential: it's OpenEMR's access control for who may see a visit, and is only passed on so the Worklist shows the row to the users allowed to see the visit.
- **Requires openhealthcare-common 1.5.0,** whose shared Worklist contract the tab is built on. On a manual install where another module's older copy of the library loads first, the tab is left out rather than stop the page.

## 0.2.2 (2026-10-26)

- **Much faster on years of data: an index on core's `form_questionnaire_assessments.response_id`.** The visit-record lookup joins on that column, which core leaves unindexed, so it read every assessment form for every response. On a test copy of a mid-size clinic (20,000 patients, 200,000 visits, 18,609 questionnaire responses including 3,225 visit records, MariaDB 12.3.3), the 2025 report went from 203 s to 3.4 s, with the same output. The index is added on install and on upgrade (`sql/0_2_1-to-0_2_2_upgrade.sql`), shared by name with the UDS and CCBHC Report modules, so it's made once.
- **Pages are announced in your language.** Each page now declares the signed-in user's OpenEMR language and its direction instead of English, so a screen reader reads the translated text in that language. It comes from openhealthcare-common 1.4.0, which this version requires. If another module's older copy of the library loads first on a site that installs by hand, the pages say English, as before, rather than stop.
- **Manual installs:** the README now says that every module's own copy of openhealthcare-common must be the same version, since OpenEMR loads the library once, from the first module that registers it.
- **The CC0 text is shipped:** `LICENSES/CC0-1.0.txt` now comes with the module, for its own maps (`data/race-groups.json`, `data/provider-roles.json`, `data/fpar-tables.json`), which the README dedicates to the public domain.

## 0.2.1 (2026-10-26)

- **LOINC's notice** in the README, as LOINC's licence asks of the LOINC codes and names `data/fpar2.json` passes on. It only linked the licence before.

## 0.2.0 (2026-10-26)

- **OPA's tables, before you upload.** The page shows Tables 1 to 9, 11 and 12 as OPA's system builds them from the file (FPAR 2.0 Implementation Guide, September 2025, Appendix A): each person once, at their age on June 30; sex, race, ethnicity, insurance and English proficiency from their latest visit; income from their latest visit with income and household size, as a percentage of the year's HHS poverty guidelines; the primary method from their latest visit with a method at exit, as OPA's FAQ counts it. Rows are placed by OPA's names for its values (`data/fpar-tables.json`, our map; OPA publishes no crosswalk), and a test fails when OPA lists a method the map doesn't place. Tables 13 and 14 are entered in OPA's portal, and Table 10 is retired.
- **OPA's checks on the tables** (Data Quality Checks Overview, December 2025), with the cells they look at: the age checks (female sterilization under 20, vasectomy under 18 for a woman relying on it, vasectomy under 20 for a man), "Withdrawal or other" and "No method, other reason" at 10 percent or more, the method unknown at 5 percent or more overall and within each age group, race and ethnicity unknown by sex, income and insurance unknown, everyone in one insurance category, and chlamydia users equal to gonorrhea tests.
- **The previous year check:** every cell that changed by 50 percent or more from the year before, or from none, against the prior year as OpenEMR builds it today with the same settings. OPA compares with what was submitted, and the page says so.
- **The two-file upload:** an encounter file (the combined layout without the six result columns) and a lab results file (one row per test result, with OPA's test type code), from OPA's Sample File of April 2026, beside the combined file. Each download names its file in the audit log.
- **A staff guide** (`GUIDE.md`), by role and task, and a **Help** link on the page to the guide at the installed version. A test checks that every label the guide quotes is on the page.
- **A setting for which HHS poverty guidelines Table 4 uses:** the 48 contiguous states and DC, Alaska or Hawaii. The figures come from the UDS Report's file, each with its HHS source; a year or region without figures shows Table 4 as not computed.
- **Checked:** unit tests on PHP 8.2 and 8.5 (35, 13 of them new); each of six deliberate breaks (the June 30 age, AGC as HSIL or higher, no method by pregnancy, the 50 percent threshold, the age check's columns, results-only lab rows) fails a test; the new tests fail on 0.1.3's source.

## 0.1.3 (2026-10-26)

- **Text and buttons are easier to read on this module's pages.** OpenEMR's blue (#007bff) is 3.97:1 on white, under the 4.5:1 that WCAG 2.1 AA asks of normal text and that HHS's Section 504 rule requires from 2027-05-11. Under OpenEMR's light and manila themes, the module's links and primary buttons now use #0056b3 (7.0:1), and muted and warning text darker shades that stay readable on tinted rows, and a link inside text is underlined; the dark, solar and colour themes keep their own colours, and OpenEMR's own pages are unchanged.

## 0.1.2 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without `getProjectDir()` and the other getters, which came with 8.1.0, and the module called them wherever the class existed. It now reads the values OpenEMR stores there, and openhealthcare-common 1.3.3 does the same for the web root, the kernel and the session (8.0.x's session factory has only `getWrapper()`). Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.
- **Fixed: a visit record saved on OpenEMR 8.4.1 or older was left out of the file.** Before 8.5.0 (#14654), OpenEMR stored a wrong visit number with each saved questionnaire (the leading digits of the visit's ID, or 0), and it doesn't repair rows saved before an upgrade. The report now takes the visit from the questionnaire form's own record, then from the stored number when it is one of the patient's visits, then from the patient's visit on the day the record was saved, a Title X visit category's first and then one at a Title X facility, so a same-day dental or primary care visit doesn't take the Title X visit's record.
- **Fixed: on OpenEMR 7.0.0 to 7.0.3 the visit query stopped once visit categories were set.** It read `interpreter_needed`, which OpenEMR added in 7.0.4. Each optional demographics column (language, interpreter, ethnicity, race, monthly income, family size) is now read only where the site has it, and counts as unanswered where it doesn't; the Layout Editor can also drop an empty one (#14742).
- **Unfinished visit records no longer count once a site saves finished ones.** OpenEMR's questionnaire runtime 0.4.0 (in 8.5.0) saves a submitted record as completed; every earlier one saved all records in-progress. The rule is openhealthcare-common 1.3.0's: until a site's first completed record, every record counts; after it, only completed or amended ones saved later. A record marked entered-in-error never counts. The rule applies only where the site's runtime is 0.4.0 or later (openhealthcare-common 1.3.3): on an older one, which saves every response in-progress, every response but entered-in-error counts, as before, even when an API client has written a completed one.
- **Sliding Fee's assessments** are still read only when that module is installed; the footprint sheet now names their table as Sliding Fee's, not OpenEMR's.
- **Checked:** unit tests on PHP 8.2 and 8.5, including the queries' parts and binds without a database; the five new tests fail on 0.1.1's source, and a sixth checks the same-day order.

## 0.1.1 (2026-10-25)

- **Fixed: saving the settings and downloading the FPAR file stopped on OpenEMR 7.0.x.** Both read form fields with `InputBag::getString()`, which Symfony added in 6.3, and OpenEMR 7.0.x ships Symfony 5.4. The report page itself worked, so a check that only loaded pages passed. The nightly fresh installs (7.0.2 and 8.5.0) now post the forms as the page posts them.
- **Checked:** fresh official 7.0.2 and 8.5.0 installs, with the settings saved and the file downloaded; unit tests.

## 0.1.0 (2026-10-24)

- First version: the FPAR 2.0 combined file for a calendar year, with OPA's 48 columns and codes from OPA's valid values file (4/3/2026). Which visits count is a setting (visit categories, and facilities in the project), plus any visit with Clinic Forms' Title X visit record, whose answers are read by FPAR's question codes. Visits missing one of OPA's five keys are left out and listed; visits to fix and OPA's data quality warnings are listed before download. No SNOMED CT US Extension code is shipped.
