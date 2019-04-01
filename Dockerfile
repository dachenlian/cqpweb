FROM ubuntu:16.04

ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update && apt-get install locales -y

# Set the locale
RUN sed -i -e 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/' /etc/locale.gen && \
    locale-gen
ENV LANG en_US.UTF-8  
ENV LANGUAGE en_US:en  
ENV LC_ALL en_US.UTF-8 

RUN apt-get update && apt-get install -y software-properties-common && add-apt-repository ppa:ondrej/php && \
    add-apt-repository ppa:ondrej/apache2 && apt-get update 

RUN apt-get install -y apache2 mysql-client \
    mysql-server php7.3 php7.3-mysql php7.3-dev php7.3-gd php7.3-mysqlnd\
    php7.3-memcache php7.3-pspell php7.3-snmp snmp php7.3-xmlrpc \
    libapache2-mod-php7.3 php7.3-cli r-base nano sendmail subversion bison flex libwww-perl

RUN svn co http://svn.code.sf.net/p/cwb/code/cwb/trunk /tmp/cwb && svn co http://svn.code.sf.net/p/cwb/code/perl/trunk /tmp/cwb-perl

WORKDIR /tmp/cwb/
RUN ./install-scripts/install-linux

ENV PATH="/usr/local/cwb-3.4.15/bin:${PATH}"

WORKDIR /tmp/cwb-perl/CWB
RUN perl Makefile.PL && make && make test && make install

WORKDIR /tmp/cwb-perl/CWB-CL
RUN perl Makefile.PL && make && make test && make install

RUN mkdir -p /var/lock/apache2 /var/run/apache2 /usr/local/cwb-3.4.15/share/cwb/registry /cqp/upload /corpora/data

COPY ./src /tmp/src
COPY ./CQPweb /CQPweb

WORKDIR /tmp/src

VOLUME /var/lib/mysql /corpora /usr/local/share/cwb/registry /cqp

RUN cp /tmp/src/php.ini /etc/php/7.3/apache2/php.ini && \
    cp /tmp/src/servername.conf /etc/apache2/conf-available/servername.conf && \
    cp /tmp/src/000-default.conf /etc/apache2/sites-enabled/000-default.conf && \
    ln -sf /etc/apache2/conf-available/servername.conf /etc/apache2/conf-enabled/servername.conf

EXPOSE 80
