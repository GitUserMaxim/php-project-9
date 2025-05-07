CREATE TABLE IF NOT EXISTS urls (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE url_checks (
    id SERIAL PRIMARY KEY,
    url_id INT NOT NULL,
    status_code INT,
    h1 TEXT NULL,
    title TEXT NULL,
    description TEXT NULL,
    created_at TIMESTAMP(0) DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (url_id) REFERENCES urls(id)
);