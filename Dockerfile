# Lightweight image for local development of the WeatherApp.
FROM php:8.2-cli

WORKDIR /var/www/html

# Copy the application (respects .dockerignore for var/, .env, etc.).
COPY . .

EXPOSE 8000

# Built-in PHP server is sufficient for this app.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "."]
