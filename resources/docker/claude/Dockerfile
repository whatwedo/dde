FROM node:22-slim

RUN usermod -l developer -d /home/developer -m node && \
    groupmod -n developer node && \
    npm install -g @anthropic-ai/claude-code && \
    rm -rf /var/lib/apt/lists/*

USER developer
WORKDIR /workspace
CMD ["sleep", "infinity"]
