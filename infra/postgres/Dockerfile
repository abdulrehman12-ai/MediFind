# syntax=docker/dockerfile:1.7
# MediFind Phase 1A local-development PostGIS image. Not production-ready.
# Applies Debian security updates for libgnutls30 and replaces the image's
# Go 1.18 gosu with the latest official gosu 1.19. PostgreSQL 16 / PostGIS 3.5
# are unchanged. PGDG removed bullseye-pgdg from apt.postgresql.org at the
# end of September 2026; apt reads that suite from apt-archive.postgresql.org.
# The pinned libgnutls30 file is no longer in the live debian-security pool,
# so that one source is the Debian snapshot that first published it.

FROM tianon/gosu:1.19@sha256:5afac3970da83806ba3d7789a3a42da92fbe4f703d94984c601e183208d035d3 AS gosu

FROM postgis/postgis:16-3.5@sha256:94146ac37bc61e2322f88016056c5920729cb8c64c8542ed590af8fc2abdac07
LABEL org.opencontainers.image.title="medifind-postgis"
LABEL org.opencontainers.image.description="MediFind Phase 1A local-development PostGIS image. Not production-ready."
LABEL medifind.image.role="local-development"

USER root
COPY --from=gosu /gosu /usr/local/bin/gosu

RUN sed -i 's|http://apt.postgresql.org/pub/repos/apt/|https://apt-archive.postgresql.org/pub/repos/apt/|' /etc/apt/sources.list.d/pgdg.list \
    && sed -i 's|deb http://deb.debian.org/debian-security|deb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20260522T153348Z|' /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends libgnutls30=3.7.1-5+deb11u10 \
    && rm -rf /var/lib/apt/lists/* /tmp/* \
    && chmod 0755 /usr/local/bin/gosu \
    && gosu --version \
    && test "$(dpkg-query -W -f='${Version}' libgnutls30)" = "3.7.1-5+deb11u10" \
    && printf 'libgnutls30=%s\ngosu=%s\n' "$(dpkg-query -W -f='${Version}' libgnutls30)" "$(gosu --version | tr '\n' ' ')" \
        > /etc/medifind-localdev-packages.txt
