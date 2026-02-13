.PHONY: test coverage coverage-open build-test shell

# Run tests (fast, no coverage)
test:
	docker compose -f docker-compose.test.yml run --rm test

# Run tests with HTML coverage report
coverage:
	docker compose -f docker-compose.test.yml run --rm coverage

# Open coverage report in browser (macOS)
coverage-open: coverage
	open coverage/index.html

# Build the test image (only needed after Dockerfile changes)
build-test:
	docker compose -f docker-compose.test.yml build

# Open a shell in the test container (useful for debugging)
shell:
	docker compose -f docker-compose.test.yml run --rm --entrypoint bash test
