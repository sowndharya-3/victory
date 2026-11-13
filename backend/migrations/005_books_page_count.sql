-- SP-6 reader (plan Task 17): cached PDF page count, filled lazily by PdfPageRenderer::pageCount
-- and cleared when a new PDF is uploaded. Additive; one-shot like 001-004 (re-running fails on the duplicate column).
ALTER TABLE books ADD COLUMN page_count INT NULL;
