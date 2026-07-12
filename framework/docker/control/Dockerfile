# Whitelisted preview-lane control service. Talks to the host Docker daemon via
# the mounted socket; every action is a fixed argv list (see server.py). Reached
# only through Caddy on home.hilos, i.e. in-tailnet only.
FROM docker:27-cli
RUN apk add --no-cache python3
COPY server.py /server.py
EXPOSE 8099
CMD ["python3", "/server.py"]
