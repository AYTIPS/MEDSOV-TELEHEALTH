from __future__ import annotations

import html
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

OUTPUT = Path("docs/medsov-telehealth-progress-report.docx")


def esc(value: object) -> str:
    return html.escape(str(value), quote=True)


def paragraph(text: str = "", style: str | None = None, bold: bool = False) -> str:
    style_xml = f'<w:pStyle w:val="{style}"/>' if style else ""
    bold_xml = "<w:b/>" if bold else ""
    return (
        "<w:p>"
        f"<w:pPr>{style_xml}</w:pPr>"
        "<w:r>"
        f"<w:rPr>{bold_xml}</w:rPr>"
        f"<w:t>{esc(text)}</w:t>"
        "</w:r>"
        "</w:p>"
    )


def bullet(text: str) -> str:
    return (
        '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/>'
        '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>'
        f"<w:r><w:t>{esc(text)}</w:t></w:r></w:p>"
    )


def table(headers: list[str], rows: list[list[object]]) -> str:
    def cell(value: object, header: bool = False) -> str:
        shading = '<w:shd w:fill="EF233C"/>' if header else ""
        bold = "<w:b/>" if header else ""
        color = '<w:color w:val="FFFFFF"/>' if header else ""
        return (
            "<w:tc>"
            f"<w:tcPr>{shading}<w:tcW w:w=\"2400\" w:type=\"dxa\"/></w:tcPr>"
            "<w:p><w:r>"
            f"<w:rPr>{bold}{color}</w:rPr>"
            f"<w:t>{esc(value)}</w:t>"
            "</w:r></w:p>"
            "</w:tc>"
        )

    xml = [
        "<w:tbl>",
        "<w:tblPr><w:tblW w:w=\"0\" w:type=\"auto\"/>"
        "<w:tblBorders>"
        '<w:top w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        '<w:left w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        '<w:right w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="D9D9D9"/>'
        "</w:tblBorders></w:tblPr>",
    ]
    xml.append("<w:tr>")
    for header in headers:
        xml.append(cell(header, True))
    xml.append("</w:tr>")
    for row in rows:
        xml.append("<w:tr>")
        for value in row:
            xml.append(cell(value))
        xml.append("</w:tr>")
    xml.append("</w:tbl>")
    return "".join(xml)


def build_document_body() -> str:
    sections: list[str] = []

    sections.append(paragraph("Medsov Telehealth Module Progress Report", "Title"))
    sections.append(paragraph("OpenEMR v8.x Custom Telehealth Module", "Subtitle"))
    sections.append(paragraph("Prepared for technical interview review", bold=True))
    sections.append(paragraph("Report date: June 14, 2026"))

    sections.append(paragraph("Executive Summary", "Heading1"))
    sections.append(
        paragraph(
            "The Medsov Telehealth module is an OpenEMR custom module that adds embedded Jitsi-based virtual care workflows directly into OpenEMR appointments and the Patient Portal. "
            "The implementation has reached a working end-to-end state for provider launch, patient portal access, waiting room, admission, notifications, access control, cancellation handling, and local email testing."
        )
    )
    sections.append(
        paragraph(
            "Current project completion is estimated at approximately 85%. The remaining work is mainly the administrative audit log UI, final packaging, broader automated regression testing, reminder scheduling, and encounter/clinical documentation integration."
        )
    )

    sections.append(paragraph("Current Status", "Heading1"))
    sections.append(
        table(
            ["Area", "Status", "Summary"],
            [
                ["Overall progress", "85% complete", "Core provider, patient, waiting-room, notification, security, and cancellation flows are implemented."],
                ["OpenEMR integration", "Working", "Telehealth starts from the OpenEMR calendar appointment modal instead of a separate external page."],
                ["Jitsi integration", "Working", "Public open-source Jitsi is embedded inside OpenEMR and Patient Portal pages."],
                ["Patient Portal", "Working", "Patients can view telehealth appointments, check devices, wait for admission, and join after approval."],
                ["Notifications", "Working", "Provider receives UI alert, OpenEMR Message Center item, and email. Patient receives invite, update, provider-started, and cancellation emails."],
                ["Security", "Working", "Assigned provider, admin, and patient access rules are enforced."],
                ["Remaining", "In progress", "Audit UI, final packaging, scheduled reminders, final regression pass, and encounter linking remain."],
            ],
        )
    )

    sections.append(paragraph("Completed Work", "Heading1"))
    completed_items = [
        "Set up Docker-based OpenEMR development environment with MariaDB and module volume mounting.",
        "Configured Mailpit for local SMTP testing without sending real external emails.",
        "Created the Medsov OpenEMR custom module structure using a modern module architecture inspired by the Weno module pattern.",
        "Added module metadata, bootstrap loading, service classes, templates, SQL support, and development installation script.",
        "Created telehealth session storage to connect OpenEMR appointments with Medsov/Jitsi meeting rooms.",
        "Added a Medsov Telehealth appointment category inside OpenEMR calendar.",
        "Added Medsov-branded telehealth controls inside the OpenEMR appointment modal.",
        "Implemented provider-side Start Telehealth from the calendar appointment page.",
        "Embedded public Jitsi using the external API inside OpenEMR instead of sending the provider to an external meeting link.",
        "Added patient device-check flow before joining the telehealth session.",
        "Built Patient Portal telehealth appointment list and patient waiting room.",
        "Built patient-side embedded Jitsi launch page after provider admission.",
        "Implemented provider waiting-room management: patient waits, provider sees waiting status, provider admits patient.",
        "Added Medsov floating provider alert when a patient enters the waiting room.",
        "Added OpenEMR native Message Center notification when patient is waiting.",
        "Added provider waiting-room email notification through OpenEMR SMTP configuration.",
        "Added patient appointment invitation email with date, time, provider, and portal link.",
        "Added patient reschedule/update email when appointment details change.",
        "Added patient provider-started email when the doctor starts the visit first.",
        "Added appointment cancellation workflow: cancelled session status, patient cancellation email, portal removal, and audit entry.",
        "Implemented access controls so Doctor A cannot start/admit Doctor B's telehealth appointment, admin can manage all, and patients can only access their own appointment.",
        "Added audit event logging for major telehealth workflow events.",
        "Created dummy patients and appointments for local demonstration and testing.",
        "Created interview-ready time tracking workbook with estimates, actual effort, variance, status, and progress reporting.",
    ]
    for item in completed_items:
        sections.append(bullet(item))

    sections.append(paragraph("Technical Implementation Summary", "Heading1"))
    sections.append(
        table(
            ["Component", "Implementation"],
            [
                ["Module bootstrap", "Registers the Medsov module with OpenEMR and injects provider appointment UI and notification behavior."],
                ["MeetingRoomService", "Owns session creation, appointment lookup, authorization checks, waiting-room state, admission state, cancellation state, and audit logging."],
                ["NotificationService", "Owns provider and patient email delivery, OpenEMR native messages, duplicate invite protection, reschedule emails, and cancellation emails."],
                ["Provider launch page", "Embeds Jitsi inside OpenEMR and allows provider to admit waiting patients."],
                ["Patient portal pages", "Show upcoming virtual visits, perform device check, wait for admission, and join embedded Jitsi session."],
                ["Mailpit", "Captures outgoing email during local development and testing."],
            ],
        )
    )

    sections.append(paragraph("Testing Completed", "Heading1"))
    test_items = [
        "Verified Docker stack starts OpenEMR and database services.",
        "Verified provider can create Medsov Telehealth appointment from the OpenEMR calendar.",
        "Verified provider can open embedded Jitsi from the appointment modal.",
        "Verified patient can log into Patient Portal and view eligible telehealth appointments.",
        "Verified patient can enter waiting room and complete device-check flow.",
        "Verified provider receives floating alert when patient is waiting.",
        "Verified provider receives OpenEMR Message Center notification.",
        "Verified provider receives waiting-room email in Mailpit.",
        "Verified provider can admit patient and patient can enter the meeting.",
        "Verified unauthorized provider cannot manage another provider's telehealth appointment.",
        "Verified patient cannot access another patient's appointment.",
        "Verified appointment invite, reschedule, provider-started, and cancellation emails in Mailpit.",
        "Verified cancelled telehealth appointments are removed from the patient portal joinable list.",
    ]
    for item in test_items:
        sections.append(bullet(item))

    sections.append(paragraph("Time Tracking Summary", "Heading1"))
    sections.append(
        table(
            ["Metric", "Value"],
            [
                ["Tracking basis", "1 development day = 8 hours"],
                ["Detailed tasks tracked", "73"],
                ["Estimated detailed scope", "467 hours / 58.38 days"],
                ["Actual human-equivalent effort logged", "393 hours / 49.12 days"],
                ["Current completion", "85.2%"],
                ["Primary time tracking file", "docs/medsov-telehealth-time-tracking-interview.xlsx"],
            ],
        )
    )

    sections.append(paragraph("Remaining Work", "Heading1"))
    remaining_items = [
        "Build Telehealth Audit Log UI for administrators to view/filter audit records.",
        "Complete final module packaging and production install/upgrade validation.",
        "Add scheduled appointment reminder emails before visit time.",
        "Complete automated regression tests for provider, patient, notification, cancellation, and access-control flows.",
        "Add session end/duration tracking and improve meeting close-out behavior.",
        "Integrate completed telehealth visits with OpenEMR encounter or clinical documentation workflow.",
        "Perform final security review around portal access, provider authorization, cancellation edge cases, and session state transitions.",
        "Finalize documentation for installation, configuration, testing, and user workflow.",
    ]
    for item in remaining_items:
        sections.append(bullet(item))

    sections.append(paragraph("Risks and Blockers", "Heading1"))
    risks = [
        "OpenEMR event hooks and calendar behavior require careful testing because appointment modals and calendar refresh behavior are complex.",
        "Public meet.jit.si is useful for development, but production deployment may require a dedicated Jitsi domain for stronger control and reliability.",
        "Native OpenEMR notification behavior depends on user roles, provider setup, and Message Center configuration.",
        "Final packaging must be validated separately from the development-mounted module workflow.",
        "Automated browser testing should be expanded before final delivery to reduce regression risk.",
    ]
    for item in risks:
        sections.append(bullet(item))

    sections.append(paragraph("Next Planned Milestones", "Heading1"))
    sections.append(
        table(
            ["Priority", "Milestone", "Estimated Effort"],
            [
                ["1", "Telehealth Audit Log UI", "12 hours / 1.5 days"],
                ["2", "Automated regression testing", "16 hours / 2 days"],
                ["3", "Final module packaging and upgrade validation", "10 hours / 1.25 days"],
                ["4", "Reminder emails", "10 hours / 1.25 days"],
                ["5", "Encounter/clinical note linking", "12 hours / 1.5 days"],
            ],
        )
    )

    sections.append(paragraph("Conclusion", "Heading1"))
    sections.append(
        paragraph(
            "The project has progressed from initial requirements review into a functional OpenEMR telehealth workflow. "
            "The current implementation demonstrates the core clinical flow: create appointment, notify patient, patient enters waiting room, assigned provider receives notification, provider admits patient, and both sides join an embedded Jitsi visit inside OpenEMR or the Patient Portal. "
            "The next phase should focus on audit visibility, packaging, regression testing, and final production-readiness work."
        )
    )

    return "".join(sections)


def build_docx() -> None:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)

    document_xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    {build_document_body()}
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="720" w:footer="720" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>"""

    styles_xml = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:rPr><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Title">
    <w:name w:val="Title"/>
    <w:rPr><w:b/><w:color w:val="EF233C"/><w:sz w:val="40"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Subtitle">
    <w:name w:val="Subtitle"/>
    <w:rPr><w:color w:val="404040"/><w:sz w:val="26"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading1">
    <w:name w:val="heading 1"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="Normal"/>
    <w:rPr><w:b/><w:color w:val="111827"/><w:sz w:val="30"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="ListParagraph">
    <w:name w:val="List Paragraph"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:ind w:left="720"/></w:pPr>
  </w:style>
</w:styles>"""

    numbering_xml = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:abstractNum w:abstractNumId="0">
    <w:lvl w:ilvl="0">
      <w:start w:val="1"/>
      <w:numFmt w:val="bullet"/>
      <w:lvlText w:val="•"/>
      <w:lvlJc w:val="left"/>
      <w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr>
    </w:lvl>
  </w:abstractNum>
  <w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>
</w:numbering>"""

    content_types = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>"""

    root_rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>"""

    document_rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
</Relationships>"""

    core_xml = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Medsov Telehealth Module Progress Report</dc:title>
  <dc:creator>Medsov Telehealth Project</dc:creator>
  <cp:lastModifiedBy>Medsov Telehealth Project</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">2026-06-14T00:00:00Z</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">2026-06-14T00:00:00Z</dcterms:modified>
</cp:coreProperties>"""

    app_xml = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
  <Application>Microsoft Word</Application>
</Properties>"""

    with ZipFile(OUTPUT, "w", ZIP_DEFLATED) as zf:
        zf.writestr("[Content_Types].xml", content_types)
        zf.writestr("_rels/.rels", root_rels)
        zf.writestr("word/document.xml", document_xml)
        zf.writestr("word/_rels/document.xml.rels", document_rels)
        zf.writestr("word/styles.xml", styles_xml)
        zf.writestr("word/numbering.xml", numbering_xml)
        zf.writestr("docProps/core.xml", core_xml)
        zf.writestr("docProps/app.xml", app_xml)

    with ZipFile(OUTPUT, "r") as zf:
        bad_file = zf.testzip()
        if bad_file:
            raise RuntimeError(f"DOCX validation failed at {bad_file}")

    print(OUTPUT.resolve())


if __name__ == "__main__":
    build_docx()
