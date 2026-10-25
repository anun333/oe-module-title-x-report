# Changelog

## 0.1.1 (2026-10-25)

- **Fixed: saving the settings and downloading the FPAR file stopped on OpenEMR 7.0.x.** Both read form fields with `InputBag::getString()`, which Symfony added in 6.3, and OpenEMR 7.0.x ships Symfony 5.4. The report page itself worked, so a check that only loaded pages passed. The nightly fresh installs (7.0.2 and 8.5.0) now post the forms as the page posts them.
- **Checked:** fresh official 7.0.2 and 8.5.0 installs, with the settings saved and the file downloaded; unit tests.

## 0.1.0 (2026-10-24)

- First version: the FPAR 2.0 combined file for a calendar year, with OPA's 48 columns and codes from OPA's valid values file (4/3/2026). Which visits count is a setting (visit categories, and facilities in the project), plus any visit with Clinic Forms' Title X visit record, whose answers are read by FPAR's question codes. Visits missing one of OPA's five keys are left out and listed; visits to fix and OPA's data quality warnings are listed before download. No SNOMED CT US Extension code is shipped.
