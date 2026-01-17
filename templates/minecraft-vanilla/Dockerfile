FROM eclipse-temurin:21-jre

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates curl python3 \
	&& rm -rf /var/lib/apt/lists/*

WORKDIR /data

COPY files/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 25565

ENTRYPOINT ["/entrypoint.sh"]
