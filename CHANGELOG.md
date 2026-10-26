# Changelog

## 0.1.3 (unreleased)

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
