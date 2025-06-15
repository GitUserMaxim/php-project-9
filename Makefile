PORT ?= 8000

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

install:
	composer install

validate:
	composer validate

lint:
	composer exec --verbose phpcs -- --standard=PSR12 --colors public src templates

beauty:
	composer exec --verbose phpcbf -- --standard=PSR12 public src templates

up:
	composer update

check:
	vendor/bin/phpstan analyse --level 5 public src templates

info:
	php -S localhost:8000 -t public

migrate:
	psql "$$DATABASE_URL" -f database.sql

all: install migrate start