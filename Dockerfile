# Basis-Image: Ubuntu 20.04
FROM ubuntu:20.04

# Verhindere interaktive Eingabeaufforderungen während der Installation
ENV DEBIAN_FRONTEND=noninteractive

# Update und Installation von Apache, PHP und benötigten Modulen (inkl. GD und ZipArchive)
RUN apt-get update && \
    apt-get install -y apache2 php libapache2-mod-php php-gd php-zip && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Konfiguriere Apache so, dass er auf Port 1978 lauscht:
RUN sed -i 's/80/1978/g' /etc/apache2/ports.conf && \
    sed -i 's/:80/:1978/g' /etc/apache2/sites-available/000-default.conf

# Kopiere alle Projektdateien in den Apache DocumentRoot
COPY . /var/www/html/

# Setze Dateiberechtigungen
RUN chown -R www-data:www-data /var/www/html

# Exponiere Port 1978
EXPOSE 1978

# Starte Apache im Vordergrund
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
