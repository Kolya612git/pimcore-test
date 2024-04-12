docker compose up -d

docker exec -it (container with php) php bin/console app:product-import https://liv-cdn.pages.dev/pim/test.json
