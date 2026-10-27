# Title X Report: staff guide

For the staff who use the module day to day, task by task. What it reads from OpenEMR and why, and how to install it, are in the [README](README.md).

The module builds the FPAR 2.0 file and shows the tables OPA will build from it; it doesn't upload anything. A grant recipient uploads the file to OPA's FPAR system; a subrecipient sends it to its grant recipient. It has been tested only on made-up patients, not yet against a real submission.

## The person who prepares the FPAR

### Say which visits count, once

*Where:* the **Title X Report** menu entry, under **Which visits count**.

1. Under **Visit categories**, tick the categories that are Title X visits. A visit with a Title X visit record counts whatever its category.
2. Under **Facilities in the project**, tick the facilities in your Title X project, or none to count every facility.
3. Under **Confidential services**, tick the visit categories you use for confidential services, or none to leave the Worklist tab below off.
4. Under **Poverty guidelines for Table 4**, choose where the clinic is: the 48 contiguous states and DC, Alaska or Hawaii.
5. Choose **Save**.

OPA leaves the definition of a family planning visit to each project, which is why it is a setting. Change it only when your project changes: the comparison with last year uses today's settings for both years.

### Check the year before you download

*Where:* the **Title X Report** page. Choose the year in **Year** and choose **Show**. The page opens on last year, the year FPAR reports.

Read the page from the top:

1. The visits left out of the file. Each lacks one of the five keys OPA requires (facility, patient, visit date, birth date, sex), and OPA rejects a whole file over one such row. Fix the record and reload.
2. **OPA data quality warnings**: a value unknown for more people than OPA allows, counted at each person's latest visit.
3. The visits to fix. They are in the file, with the field in question left blank: a visit with no visit record, a method at exit with no "how provided", an insurance company with no payer code, an answer OPA doesn't accept.
4. **OPA checks on the tables**: the checks OPA's system runs once it builds the tables, with the cells they look at (T7_C1 is Table 7, column C, row 1). The age checks are here: female sterilization under 20, vasectomy under 18 for a woman relying on it, vasectomy under 20 for a man. OPA asks for a comment on each one that isn't a mistake in the record, so a check you expect (a sterilization at 19 that really happened) needs a sentence ready, not a change.
5. The comparison with the year before: every cell that changed by half or more, which OPA also flags. It compares with last year as OpenEMR builds it today; OPA compares with what you submitted. A cell that moved because of a new site, a new clinician or a setting you changed needs a comment for OPA, not a fix.

### Read the tables before OPA does

*Where:* the **Title X Report** page, under **The tables OPA will build**.

These are OPA's Tables 1 to 9, 11 and 12, built the way OPA's system builds them from the file: each person once, at their age on June 30; sex, race, ethnicity, insurance and English proficiency from their latest visit; income from their latest visit with income and household size; the primary method from their latest visit with a method at exit. Use them to spot a number that looks wrong before you upload: a site with no chlamydia tests, say, or everyone in one insurance row.

*What it doesn't do:*
- Tables 13 (staffing) and 14 (revenue) are entered in OPA's portal, and so are anonymous HIV tests (Table 12, row 5).
- No 1-month hormonal injection: OPA's values have one code for injectables, counted as the 3-month injection.
- A preliminary rapid HIV positive counts as positive until a confirmed result replaces it in the record; OPA asks for confirmed ones only.

### Download the file

*Where:* the **Title X Report** page.

- Choose **Download the FPAR file (CSV)** for OPA's combined file, one row per visit with the lab results on it. Most sites upload this one.
- Or choose **Encounter file** and **Lab results file** for the two files a site uploads when it reports lab results apart. The lab results file has one row per test result; OPA asks a site that does this to send every result in it.

Upload the files as they are. Opening and saving them in a spreadsheet program changes dates and long codes, which OPA warns about. Each download is written to OpenEMR's audit log with its row count.

## Billing staff: confidential visits

### Keep a confidential visit from reaching someone else

*Where:* the Staff Worklist, the **Confidential visits** tab. It needs the Staff Worklist module and the acct/bill permission, and lists visits from the last year in the categories ticked under **Confidential services**.

Title X asks that "Reasonable efforts to collect charges without jeopardizing client confidentiality must be made" (42 CFR 59.10(a)). Two things can break that, and each makes its own row:

- **Confidential visit queued to bill insurance**, or **Confidential visit billed to insurance**: the policyholder may see the claim on an explanation of benefits. Check the client was told and agreed to bill their insurance. While the claim is only queued, you can still take it out of the Billing Manager before it's sent. The row never names a payer.
- **Confidential visit with a balance: statements go to the guardian** (OpenEMR 8.5.0 and later, which address statements to the guardian on Demographics), or **Confidential visit with a balance: a guardian is on file** (older versions). Hold the statement for this visit or bill the client directly. For a minor, Title X staff may not notify a parent or guardian of the services (42 CFR 59.10(b)).

Choose **Open the visit** to see it. Once it's handled, dismiss the row with a reason, which the Worklist logs; each row is dismissed on its own.

## Front desk and clinicians: what the report reads

The report reads what is already in the chart. To keep a visit's row complete:

- *Demographics:* birth date, sex, race (one or more), ethnicity, language and whether an interpreter is needed.
- *Income:* the patient's sliding fee assessment, when the Sliding Fee module is installed, or monthly income and household size on Demographics.
- *Insurance:* the primary insurance on the visit date. Billing staff set each insurance company's payer code (Source of Payment Typology) in Practice › Insurance Companies.
- *The visit:* a Vitals form for blood pressure, height and weight; smoking status in History; lab orders and results that carry one of OPA's LOINC test codes.
- *The Title X visit record* from the Clinic Forms module: pregnancy status and intention, the methods at intake and exit, why none, how the method was provided, and the counseling questions.

## Who can open the report

The page and the downloads need the patients/demo permission in OpenEMR's access control, as the UDS Report does. The files hold chart numbers and birth dates, so keep them where you keep other patient records.

Choose **Help** on the page to open this guide for the version installed.
