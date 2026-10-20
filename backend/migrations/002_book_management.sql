ALTER TABLE books
  ADD COLUMN index_file_path VARCHAR(500) NULL
    COMMENT 'publicly viewable index/table-of-contents document',
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE book_preview_pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  page_number INT NOT NULL,
  file_path VARCHAR(500) NOT NULL COMMENT 'publicly viewable preview image',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_book_page (book_id, page_number),
  FOREIGN KEY (book_id) REFERENCES books(id)
);
