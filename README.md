# Title X Report

> **Status: 0.1.1, a pre-release.** Tested on OpenEMR 8.4.1 and master with synthetic data only. Check the file against your own records before you upload it.

The **FPAR 2.0** file for the Office of Population Affairs (OPA), from OpenEMR: one row per family planning visit in the calendar year, with the column names OPA's upload maps by and the codes OPA accepts. A Title X grant recipient uploads it to the FPAR system; a subrecipient sends it to its grant recipient.

## What it does

- **Which visits count** is the site's setting, because OPA leaves the definition of a family planning visit to each project: the visit categories that are Title X visits, plus any visit with a Title X visit record, at the facilities in the Title X project (all of them when none is chosen).
- **The file** has all 48 columns of OPA's combined layout, in UTF-8, with dates as `YYYY-MM-DD`. Each column comes from OpenEMR's own records:
  - the facility's NPI (or a stable `facility-<id>`), the provider's NPI, and the provider's role from their NUCC taxonomy;
  - the patient's chart number, birth date, sex, language, ethnicity and races (CDC codes from OpenEMR's lists);
  - income and household size from [Sliding Fee](https://github.com/anun333/oe-module-sliding-fee)'s assessment in force on the visit date (a minor's own resources when assessed that way), else the demographics;
  - coverage type and payer from the primary insurance's Source of Payment Typology code;
  - blood pressure, height and weight from the visit's Vitals form, and smoking status from History;
  - the six tests (Pap, HPV, chlamydia, gonorrhea, HIV, syphilis) from the visit's lab orders and results, when they carry one of OPA's LOINC test codes, with each result read as one of OPA's values;
  - pregnancy status and intention, the contraceptive methods at intake and exit, why none, how the method was provided, the two counseling questions and the optional "want to talk about contraception" question, from the Title X visit record that [Clinic Forms](https://github.com/anun333/oe-module-clinic-forms) adds as an OpenEMR questionnaire. The report reads its answers by FPAR's question codes, not its wording.
- **Before you upload:**
  - visits missing one of the five keys OPA requires (facility, patient, visit date, birth date, sex) are left out of the file and listed, since OPA rejects a whole file over one such row;
  - visits to fix are listed: a Title X visit with no visit record, a method at exit with no "how provided", a method given with a reason for none, an answer OPA doesn't accept, an insurance company with no payer code;
  - OPA's data quality warnings, counted per person at their latest visit: race, ethnicity, income, insurance, English proficiency or the method at exit unknown above OPA's thresholds.
- **Codes** come from OPA's own valid values file (version 4/3/2026), generated into `data/fpar2.json` by `tools/fpar-reference.py`, never typed in. SNOMED CT US Extension codes in OPA's file aren't shipped: where an answer has one, the report writes OPA's LOINC value for the same answer, which OPA accepts (it calls them transition values and has set no end date).

## Who can open what

The page and the download need `patients/demo` in OpenEMR's access control, as the UDS Report does. The file holds patient chart numbers and birth dates, so the download is posted with the page's CSRF token, and each one is in the audit log with its row count.

## Not done yet

- Previews of OPA's Tables 1 to 9, 11 and 12.
- The separate lab file (OPA's other layout); the combined file carries the same results.
- OPA's checks that compare with the prior year, and the method checks by age (sterilization under 20).
- Combined chlamydia and gonorrhea tests ordered under a code in neither of OPA's lists count only when their results carry each test's own code.

## Install

From the OpenEMR directory:

```
composer require anun333/oe-module-title-x-report
```

The installer plugin places it in `interface/modules/custom_modules/oe-module-title-x-report/`, and Composer brings [openhealthcare-common](https://github.com/anun333/openhealthcare-common) with it. Without Composer, copy this directory there and run `composer install --no-dev` in it, which puts openhealthcare-common in its own `vendor/`; the module stays switched off until it finds that package one way or the other.

Then go to Modules › Manage Modules › Unregistered, and **Register**, **Install** and **Enable** it. Open **Title X Report**, choose the visit categories that are Title X visits, and save.

## Compatibility

Written for OpenEMR 7.0 and later through [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s compatibility layer; its bootstrap never stops a page on any version. Checked end to end on 8.4.1 and master, and on fresh official 7.0.2 and 8.5.0 installs with its pages loaded and its forms posted (from 0.1.1: 0.1.0's forms stopped on 7.0.x).

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php --check
python3 -I tools/fpar-reference.py <OPA valid values .xlsx> <OPA sample file .xlsx>   # when OPA publishes a new file
```

## Sources

- OPA, [FPAR 2.0](https://opa.hhs.gov/research-evaluation/title-x-services-research/family-planning-annual-report/fpar2): the Data Elements with Valid Values file (version 4/3/2026), the Sample File (April 2026), the Implementation Guide (September 2025), the Data Quality Checks Overview (December 2025) and the FAQ.
- 42 CFR part 59 (Title X).
- The race code ranges are the [UDS Report](https://github.com/anun333/oe-module-uds-report)'s, checked against OpenEMR's race list.

## Codes and their terms

The module's code is GPL-3.0-or-later. `data/fpar2.json` holds codes under their owners' terms: LOINC codes and names under LOINC's licence (https://loinc.org/license); SNOMED CT International Edition identifiers and terms from the SNOMED CT Global Patient Set, © International Health Terminology Standards Development Organisation (trading as SNOMED International), under CC BY-ND 4.0 (https://www.snomed.org/gps); CDC Race & Ethnicity and HL7 codes; Source of Payment Typology codes without their names. SNOMED and SNOMED CT are registered trademarks of SNOMED International. Our own maps (`data/race-groups.json`, `data/provider-roles.json`) are CC0.

## Other modules for OpenEMR

See [openhealthcare-common](https://github.com/anun333/openhealthcare-common) for the modules that share it.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).
