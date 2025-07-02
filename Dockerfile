# 2012 LAMP Stack Environment
# Using CentOS 6 base to match 2012 infrastructure
FROM centos:6

# Install EPEL repository for additional packages
RUN yum install -y epel-release

# Install core packages available in 2012
RUN yum install -y \
    httpd \
    php \
    php-mysql \
    php-snmp \
    mysql-server \
    mysql \
    net-snmp \
    net-snmp-utils \
    rrdtool \
    rrdtool-php \
    nagios \
    nagios-plugins-all \
    gcc \
    make \
    wget \
    vim

# Configure Apache for 2012 standards
RUN echo "ServerName localhost" >> /etc/httpd/conf/httpd.conf

# Set up basic PHP configuration for 2012
RUN sed -i 's/;date.timezone =/date.timezone = UTC/' /etc/php.ini

# Create application directory
RUN mkdir -p /var/www/html/netmon
WORKDIR /var/www/html/netmon

# Copy application files
COPY src/ /var/www/html/netmon/
COPY config/ /var/www/html/netmon/config/

# Set proper permissions
RUN chown -R apache:apache /var/www/html/netmon
RUN chmod -R 755 /var/www/html/netmon

# Expose web port
EXPOSE 80

# Start services (2012 style)
CMD service mysqld start && service httpd start && tail -f /var/log/httpd/access_log