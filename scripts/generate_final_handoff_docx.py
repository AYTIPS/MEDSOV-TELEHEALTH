from pathlib import Path
from xml.sax.saxutils import escape
from zipfile import ZIP_DEFLATED, ZipFile


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs" / "final-interviewer-handoff.md"
TARGET = ROOT / "docs" / "medsov-telehealth-final-handoff.docx"


def paragraph(text: str, size: int = 22, bold: bool = False) -> str:
    text = escape(text)
    bold_xml = "<w:b/>" if bold else ""
    return (
        "<w:p>"
        "<w:r>"
        f"<w:rPr>{bold_xml}<w:sz w:val=\"{size}\"/></w:rPr>"
        f"<w:t xml:space=\"preserve\">{text}</w:t>"
        "</w:r>"
        "</w:p>"
    )


def blank() -> str:
    return "<w:p/>"


def document_xml(markdown: str) -> str:
    body = []
    in_code = False

    for raw_line in markdown.splitlines():
        line = raw_line.rstrip()

        if line.startswith("```"):
            in_code = not in_code
            body.append(blank())
            continue

        if not line:
            body.append(blank())
            continue

        if in_code:
            body.append(paragraph(line, size=18))
            continue

        if line.startswith("# "):
            body.append(paragraph(line[2:], size=36, bold=True))
        elif line.startswith("## "):
            body.append(paragraph(line[3:], size=30, bold=True))
        elif line.startswith("### "):
            body.append(paragraph(line[4:], size=26, bold=True))
        elif line.startswith("- "):
            body.append(paragraph(f"- {line[2:]}", size=22))
        elif line.startswith("|"):
            body.append(paragraph(line, size=18))
        elif line.startswith("`") and line.endswith("`"):
            body.append(paragraph(line.strip("`"), size=20))
        else:
            body.append(paragraph(line, size=22))

    return (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        "<w:body>"
        + "".join(body)
        + '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
        "</w:body></w:document>"
    )


def write_docx(markdown: str) -> None:
    content_types = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
"""
    rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
"""
    document_rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>
"""

    TARGET.parent.mkdir(parents=True, exist_ok=True)
    with ZipFile(TARGET, "w", ZIP_DEFLATED) as docx:
        docx.writestr("[Content_Types].xml", content_types)
        docx.writestr("_rels/.rels", rels)
        docx.writestr("word/document.xml", document_xml(markdown))
        docx.writestr("word/_rels/document.xml.rels", document_rels)


if __name__ == "__main__":
    write_docx(SOURCE.read_text(encoding="utf-8"))
    print(f"Created {TARGET}")
