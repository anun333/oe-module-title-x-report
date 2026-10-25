#!/usr/bin/env python3
"""Write data/fpar2.json from OPA's two FPAR 2.0 workbooks, so the codes come from OPA's file, never typed in.

    python3 -I tools/fpar-reference.py <valid-values.xlsx> <sample.xlsx>

valid-values.xlsx: "FPAR 2.0 Data Elements with Valid Values", the "DEs with valid values" sheet (one row per
valid value: element, question code, missing allowed, transition flag, code system, code, description).
sample.xlsx: "FPAR 2.0 Sample File", whose three example sheets give the exact column names OPA's upload
maps by.

Both from https://opa.hhs.gov/research-evaluation/title-x-services-research/family-planning-annual-report/fpar2
Needs openpyxl (Debian: python3-openpyxl). Stops when the sample's columns and the element-to-column map below
disagree, or an element has no values, rather than writing a file that's quietly short.
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

import openpyxl

ROOT = Path(__file__).resolve().parent.parent

# Each element's CSV column. OPA's sheets give the elements and the columns but not the pairing; it follows from
# the names and the sample values (as in OPA's version log, which records the column renames).
COLUMN = {
    "1": "facility_id", "2": "provider_id", "3": "provider_role", "4": "patient_id", "5": "visit_date",
    "6": "birth_date", "7": "sex", "8": "limited_english_proficiency", "9": "ethnicity",
    "10a": "race_amindian", "10b": "race_asian", "10c": "race_black", "10d": "race_hpi", "10e": "race_white",
    "10f": "race_unknown", "11": "annual_household_income", "12": "household_size",
    "13": "insurance_coverage_type", "14": "visit_payer", "15": "pregnancy_status", "16": "pregnancy_intention",
    "17": "contraceptive_method_intake", "18": "no_contraceptive_reason_intake", "19": "contraceptive_method_exit",
    "20": "no_contraceptive_reason_exit", "21": "contraceptive_method_provided",
    "22": "contraceptive_counseling_provided", "23": "pregnancy_counseling_provided",
    "24": "blood_pressure_systolic", "25": "blood_pressure_diastolic", "26": "height", "26b": "height_unit",
    "27": "weight", "27b": "weight_unit", "28": "tobacco_smoking_status", "29": "test_pap", "30": "test_pap_result",
    "31": "test_hpv", "32": "test_hpv_result", "33": "test_chlamydia", "34": "test_chlamydia_result",
    "35": "test_gonorrhea", "36": "test_gonorrhea_result", "37": "test_hiv", "38": "test_hiv_result",
    "39": "test_syphilis", "40": "test_syphilis_result", "41": "want_talk_contraception", "n/a": "test_type",
}
US_EXTENSION = re.compile(r"^\d+1000124\d{3}$")  # SNOMED CT US Extension (namespace 1000124): never shipped (see below)
SYSTEMS = {"SNOMED CT": "http://snomed.info/sct", "LOINC": "http://loinc.org", "CDCREC": "urn:oid:2.16.840.1.113883.6.238",
           "UCUM": "http://unitsofmeasure.org", "SOP": "urn:oid:2.16.840.1.113883.3.221.5", "HL7": "hl7"}


def text(value) -> str:
    if value is None:
        return ""
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    return re.sub(r"\s+", " ", str(value)).strip()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__, file=sys.stderr)
        return 2
    valid_path, sample_path = Path(sys.argv[1]), Path(sys.argv[2])
    book = openpyxl.load_workbook(valid_path, read_only=True, data_only=True)
    readme = text(next(book["README"].iter_rows(values_only=True))[0])
    version = re.match(r"(\d{1,2}/\d{1,2}/\d{4})", readme)
    if not version:
        raise SystemExit("can't read the valid-values file's version from its README sheet")
    rows = list(book["DEs with valid values"].iter_rows(values_only=True))
    header = next(i for i, r in enumerate(rows) if text(r[0]).lower().startswith("data element number"))
    elements: dict[str, dict] = {}
    left_out: dict[str, int] = {}
    for r in rows[header + 1:]:
        de = text(r[0])
        if not de or de.lower().startswith("end of worksheet"):
            continue
        if de not in COLUMN:
            raise SystemExit(f"element {de!r} in OPA's sheet isn't in the column map: update COLUMN")
        question = text(r[2])
        element = elements.setdefault(de, {
            "element": de, "column": COLUMN[de], "name": text(r[1]),
            "question": question if re.fullmatch(r"\d+-\d", question) else None,
            "missing_allowed": text(r[4]).lower() == "yes", "free": True, "format": None, "values": [],
        })
        system, code = text(r[6]), text(r[7])
        if code in ("", "NA") or re.fullmatch(r"(?i)YYYY-MM-DD|.*\b(integer|number)\b.*", code):
            # a free-text, date or numeric element: OPA writes its format where a code would be, or in the description
            element["format"] = code if code not in ("", "NA") else (text(r[8]) or None)
            continue
        element["free"] = False
        system = SYSTEMS.get(system, system)
        if system == SYSTEMS["SNOMED CT"] and US_EXTENSION.match(code):
            # US Extension content is licensed only through the Affiliate License, which a public, worldwide package
            # can't meet; OPA's LOINC transition value for the same answer is kept and used instead.
            left_out[COLUMN[de]] = left_out.get(COLUMN[de], 0) + 1  # counted, not listed: the identifiers stay out too
            continue
        display = "" if system == SYSTEMS["SOP"] else text(r[8])  # SOP names aren't shipped: their terms aren't read yet
        element["values"].append({"system": system, "code": code, "display": display, "transition": text(r[5]).upper() == "X"})

    sample = openpyxl.load_workbook(sample_path, read_only=True, data_only=True)
    layouts = {}
    for name, key in (("Combined file", "combined"), ("Encounter-level file", "encounter"), ("Lab result-level file", "lab")):
        sheet = list(sample[name].iter_rows(values_only=True))
        head = next(r for r in sheet if text(r[0]) == "facility_id")
        layouts[key] = [text(c) for c in head if text(c)]
    mapped = {e["column"] for e in elements.values()} - {"test_type"}
    if set(layouts["combined"]) != mapped:
        raise SystemExit(f"the sample's combined columns and the map disagree: only in the sample {sorted(set(layouts['combined']) - mapped)}, "
                         f"only in the map {sorted(mapped - set(layouts['combined']))}")
    free = sorted(e["column"] for e in elements.values() if e["free"])
    if free != sorted(["facility_id", "provider_id", "patient_id", "visit_date", "birth_date", "annual_household_income", "household_size",
                       "blood_pressure_systolic", "blood_pressure_diastolic", "height", "weight"]):
        raise SystemExit(f"the free-text elements aren't the seven expected (an element lost its codes?): {free}")

    out = {
        "_about": "FPAR 2.0's valid values and file layouts, generated by tools/fpar-reference.py from OPA's own workbooks. "
                  "Regenerate when OPA publishes a new file; don't edit by hand.",
        "terms": "Codes stay under their owners' terms. LOINC codes and names: LOINC's licence (https://loinc.org/license). SNOMED CT identifiers "
                 "and terms here are International Edition content from the SNOMED CT Global Patient Set (GPS), © International Health Terminology "
                 "Standards Development Organisation (trading as SNOMED International), licensed under CC BY-ND 4.0 "
                 "(https://creativecommons.org/licenses/by-nd/4.0/), provided without warranty; see https://www.snomed.org/gps. SNOMED and SNOMED CT "
                 "are registered trademarks of SNOMED International. US Extension codes in OPA's file are left out, counted by column (left_out). Source of Payment "
                 "Typology codes are listed without their names.",
        "left_out": left_out,
        "source": {"valid_values": {"file": valid_path.name, "version": version.group(1), "sha256": sha256(valid_path)},
                   "sample": {"file": sample_path.name, "sha256": sha256(sample_path)},
                   "page": "https://opa.hhs.gov/research-evaluation/title-x-services-research/family-planning-annual-report/fpar2"},
        "layouts": layouts,
        "elements": sorted(elements.values(), key=lambda e: layouts["combined"].index(e["column"]) if e["column"] in layouts["combined"] else 99),
    }
    (ROOT / "data").mkdir(exist_ok=True)
    (ROOT / "data" / "fpar2.json").write_text(json.dumps(out, indent=1, ensure_ascii=False) + "\n")
    print(f"data/fpar2.json: OPA version {version.group(1)}, {len(elements)} elements, "
          f"{sum(len(e['values']) for e in elements.values())} values, {len(layouts['combined'])} combined columns; "
          f"{sum(left_out.values())} US Extension codes left out")
    return 0


if __name__ == "__main__":
    sys.exit(main())
