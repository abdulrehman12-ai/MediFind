# qpdf 12.4.1 official linux-x86_64 release zip. The publisher checksum is
# the qpdf-12.4.1-bin-linux-x86_64.zip line in qpdf-12.4.1.sha256.
# qpdf's CMake always adds the fuzz directory, and fuzz/CMakeLists.txt
# marks qpdf_corpus as an ALL target, so BUILD_TESTING=OFF does not
# skip that work. The verified binary avoids that build.
# Issue 1739 can still allocate during linearization checks; the Compose
# memory, CPU, and pid limits turn that into a failed check.
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS fetch

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

ADD https://github.com/qpdf/qpdf/releases/download/v12.4.1/qpdf-12.4.1-bin-linux-x86_64.zip /tmp/qpdf.zip
RUN echo "db9122e88ec00c76ac6a14e09ffb92406db1773d47b968911ff6e69f28c09bf9  /tmp/qpdf.zip" | sha256sum -c - \
    && unzip -q -d /tmp/qpdf /tmp/qpdf.zip \
    && test -x /tmp/qpdf/bin/qpdf \
    && test -L /tmp/qpdf/lib/libqpdf.so.30 \
    && test -f /tmp/qpdf/lib/libqpdf.so.30.4.1

FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgmp10 \
        libstdc++6 \
        python3 \
        zlib1g \
        perl-base=5.36.0-7+deb12u4 \
    && rm -rf /var/lib/apt/lists/* \
    && test "$(dpkg-query -W -f='${Version}' perl-base)" = "5.36.0-7+deb12u4" \
    && useradd --system --uid 65532 --home-dir /tmp --shell /usr/sbin/nologin pdfcheck

COPY --from=fetch /tmp/qpdf/bin/qpdf /usr/local/bin/qpdf
COPY --from=fetch /tmp/qpdf/lib/ /usr/local/lib/
COPY infra/pdf-worker/server.py /opt/pdf-worker/server.py

RUN /usr/local/bin/qpdf --version

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

USER 65532:65532
EXPOSE 8080
ENTRYPOINT ["python3", "/opt/pdf-worker/server.py"]
