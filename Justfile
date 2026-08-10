set dotenv-load := true

# Setup local environment and install dependencies
setup:
    @if [ ! -f .env ]; then \
        echo "Creating .env from template..."; \
        echo '# VPS Connection Settings' > .env; \
        echo 'VPS_USER="root"' >> .env; \
        echo 'VPS_HOST="62.171.172.160"' >> .env; \
        echo 'VPS_NC_PATH="/opt/nextcloud-stack/nextcloud"' >> .env; \
        echo 'APP_NAME="organization"' >> .env; \
        echo 'CONTAINER_NAME="nextcloud-app"' >> .env; \
        echo ".env created! Please verify settings."; \
    else \
        echo ".env already exists."; \
    fi
    npm install
    composer install --ignore-platform-reqs

# Deploy the app to the VPS
deploy:
    @if [ ! -f .env ]; then \
        echo "Error: .env file not found. Please run 'just setup' first."; \
        exit 1; \
    fi
    ./deploy.sh
