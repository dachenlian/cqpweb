FROM ubuntu:18.04

ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update && apt-get install -y software-properties-common && add-apt-repository ppa:ondrej/php && apt-get update

RUN apt-get install -y apache2 mysql-client \
    mysql-server php7.3 php7.3-mysql php7.3-dev php7.3-gd php7.3-mysqlnd\
    php7.3-memcache php7.3-pspell php7.3-snmp snmp php7.3-xmlrpc \
    libapache2-mod-php7.3 php7.3-cli r-base nano sendmail

RUN mkdir -p /var/lock/apache2 /var/run/apache2

COPY ./src /tmp/cwb
COPY ./CQPweb /CQPweb

WORKDIR /tmp/cwb

VOLUME /var/lib/mysql /corpora /usr/local/share/cwb/registry /cqp

RUN mkdir -p /tmp/cqp && chmod 777 /tmp/cqp && \
    ./install-cwb.sh && \
    cd /tmp/cwb/CWB-perl && \
    perl Makefile.PL && \
    make && make install && \
    cp /tmp/cwb/php.ini /etc/php/7.3/apache2/php.ini && \
    cp /tmp/cwb/servername.conf /etc/apache2/conf-available/servername.conf && \
    cp /tmp/cwb/000-default.conf /etc/apache2/sites-enabled/000-default.conf && \
    ln -sf /etc/apache2/conf-available/servername.conf /etc/apache2/conf-enabled/servername.conf

EXPOSE 80
