import os
import shutil
import csv
import io


def detect_and_read(path):
    # Try common encodings
    encodings = ["utf-8-sig", "utf-8", "cp1252", "latin1"]
    b = open(path, "rb").read()
    for enc in encodings:
        try:
            text = b.decode(enc)
            return enc, text
        except Exception:
            continue
    # fallback: decode latin1 (lossless byte->unicode mapping)
    return "latin1", b.decode("latin1")


def merge_csv_files(base_dir, filenames, out_name="merged_data_utf8_bom.csv"):
    rows = []
    field_order = []
    file_row_counts = {}

    # backup originals
    for fn in filenames:
        p = os.path.join(base_dir, fn)
        if not os.path.exists(p):
            print(f"Warning: {p} not found, skipping")
            continue
        bak = p + ".bak"
        if not os.path.exists(bak):
            shutil.copy2(p, bak)
            print(f"Backed up: {p} -> {bak}")

    # read files
    for fn in filenames:
        p = os.path.join(base_dir, fn)
        if not os.path.exists(p):
            continue
        enc, text = detect_and_read(p)
        sio = io.StringIO(text)
        reader = csv.DictReader(sio)
        count = 0
        for r in reader:
            rows.append(r)
            # preserve field order by first appearance
            for k in r.keys():
                if k not in field_order:
                    field_order.append(k)
            count += 1
        file_row_counts[fn] = count
        print(f"Read {count} rows from {fn} (detected {enc})")

    if not rows:
        print("No rows found to merge.")
        return None

    out_path = os.path.join(base_dir, out_name)
    # write with BOM so Excel on Windows detects UTF-8
    with open(out_path, "w", encoding="utf-8-sig", newline="") as outf:
        writer = csv.DictWriter(outf, fieldnames=field_order, extrasaction="ignore")
        writer.writeheader()
        for r in rows:
            # ensure all keys exist
            out = {k: (r.get(k, "") if r.get(k) is not None else "") for k in field_order}
            writer.writerow(out)

    total = sum(file_row_counts.values())
    print(f"Wrote merged file: {out_path} with {len(rows)} rows (sum of inputs {total})")
    return out_path, file_row_counts


def main():
    base_dir = os.path.dirname(__file__)
    # files to merge (order preserved)
    filenames = [
        "data_chanhtuoi_full_final.csv",
        "data_chanhtuoi_tu_khoa_da.csv",
        "data_gop.csv",
    ]

    out = merge_csv_files(base_dir, filenames)
    if out:
        path, counts = out
        print("Per-file row counts:")
        for k, v in counts.items():
            print(f"  {k}: {v}")


if __name__ == '__main__':
    main()
