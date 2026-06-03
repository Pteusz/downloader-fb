FROM docker:24-cli
RUN apk add --no-cache python3
COPY server.py /app/server.py
WORKDIR /app
CMD ["python3", "/app/server.py"]
