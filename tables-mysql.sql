CREATE TABLE IF NOT EXISTS /*_*/page_requestparams (
    page_id INT UNSIGNED NOT NULL,
    param_name VARCHAR(255) NOT NULL,
    PRIMARY KEY (page_id, param_name)
) /*$wgDBTableOptions*/;
