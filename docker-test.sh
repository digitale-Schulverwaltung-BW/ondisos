#!/bin/bash
# Docker Test Runner Script
# Convenient wrapper for running tests in Docker container

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if docker-compose is running
if ! docker-compose ps | grep -q "ondisos-backend.*Up"; then
    echo -e "${RED}❌ Backend container is not running!${NC}"
    echo -e "${YELLOW}Start it with: docker-compose up -d${NC}"
    exit 1
fi

echo -e "${GREEN}🧪 Running tests in Docker container...${NC}\n"

# Run tests based on arguments
if [ $# -eq 0 ]; then
    # No arguments: run all tests
    echo "Running all tests..."
    docker-compose exec backend composer test
elif [ "$1" = "unit" ]; then
    # Run only unit tests
    echo "Running unit tests..."
    docker-compose exec backend composer test -- --testsuite=Unit
elif [ "$1" = "integration" ]; then
    # Run only integration tests
    echo "Running integration tests..."
    docker-compose exec backend composer test -- --testsuite=Integration
elif [ "$1" = "coverage" ]; then
    # Run with coverage
    echo "Running tests with code coverage..."
    docker-compose exec backend composer test:coverage
    echo -e "\n${GREEN}Coverage report: backend/coverage/index.html${NC}"
elif [ "$1" = "filter" ] && [ -n "$2" ]; then
    # Run specific test class
    echo "Running tests matching: $2"
    docker-compose exec backend composer test:filter "$2"
elif [ "$1" = "watch" ]; then
    # Watch mode (re-run on file changes) - requires watchexec
    echo "Watch mode not implemented yet"
    echo "Install watchexec and use: watchexec -e php -- $0"
else
    # Pass all arguments to phpunit
    echo "Running custom test command..."
    docker-compose exec backend ./vendor/bin/phpunit "$@"
fi

exit_code=$?

if [ $exit_code -eq 0 ]; then
    echo -e "\n${GREEN}✅ All tests passed!${NC}"
else
    echo -e "\n${RED}❌ Some tests failed!${NC}"
fi

exit $exit_code
