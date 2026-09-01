FROM postgres:16-alpine

RUN apk add --no-cache git build-base clang19 llvm19-dev

RUN git clone --branch v0.7.4 https://github.com/pgvector/pgvector.git /tmp/pgvector \
    && cd /tmp/pgvector \
    && make \
    && make install \
    && rm -rf /tmp/pgvector

RUN apk del git build-base clang19 llvm19-dev
