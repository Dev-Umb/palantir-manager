## Capability preservation
Existing AI conversation/composer/run, data queries, HTML reports and business proposal tools retain their actual entry points. Add prepare_fixed_quotation tool and quotation_docx artifact. Existing reference quotation routes/archives remain unchanged. No new menu.

## Template fidelity
Retain resources/quotation-template.docx verbatim with SHA256 guard. Only word/document.xml changes. Title, date, contact/phone, product and price red slots may change. Clone the exact original row per product; sequence values change automatically while sequence formatting remains. Material/processing unknown values use —; unit is appended within editable price text without a new column. Fixed remarks and company identity remain unchanged. Preserve original editable red color as supplied. No detached image-generation or reconstructed Word document.

## Generation
Tool returns partial data in an editable card; only named allowed draft fields are accepted. Server validates complete input, tax/transport compatibility, price consistency and lengths. User clicks Generate. Endpoint verifies existing AI permission, quotation role access, owner run and completed status. Generated DOCX bytes and inputs freeze atomically in the existing artifact; duplicate identical requests return same artifact, differing input returns conflict. Owner-only download returns frozen DOCX. No schema or business state workflow. Existing AI run persistence is reused.

## Validation
Test tools/artifact routing, card missing fields/zero values, failed generation retention, ready historical download, owner/role/run boundaries and exact package preservation. Render original and generated source with same renderer and inspect all pages; preserve original Chinese fonts, even if local fallback must be installed into the bundled renderer only for QA.

## Render finding and bounded correction
Original seal is anchored to the page, not the flowing signature paragraph. For additional detail rows, shift only its vertical offset by the original row height (592 twips per added row); all other drawing properties/media stay unchanged. Single-page first version supports at most three rows, titles20/contact12/product24 characters, and rejects excess without truncating or shrinking. Render original and 1/2/3-row outputs with the same actual SimSun/Times New Roman QA fonts borrowed read-only from Legato.
