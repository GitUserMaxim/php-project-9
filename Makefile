PORT ?= 8000

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

install:
	composer install

validate:
	composer validate

lint:
	composer exec --verbose phpcs -- --standard=PSR12 --colors public

beauty:
	composer exec --verbose phpcbf -- --standard=PSR12 public

up:
	composer update

test:
	XDEBUG_MODE=coverage composer exec --verbose phpunit tests --verbose

test-coverage:
	XDEBUG_MODE=coverage composer exec --verbose phpunit tests -- --coverage-clover build/logs/clover.xml

test-coverage-html:
	XDEBUG_MODE=coverage composer exec --verbose phpunit tests -- --coverage-html build/coverage/html

check:
	vendor/bin/phpstan analyse --level 5 src