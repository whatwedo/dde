# Base stage (used in development and production)
FROM whatwedo/nginx:v2.0 as base

# Set workdir
WORKDIR /var/www

########################################################################################################################

# Development stage (depencencies and configuration used in development only)
FROM base as dev

# Install dde development depencencies
# .dde/configure-image.sh will be created automatically
COPY .dde/configure-image.sh /tmp/dde-configure-image.sh
ARG DDE_UID
ARG DDE_GID
RUN /tmp/dde-configure-image.sh

########################################################################################################################

# Production stage (depencencies and configuration used in production only)
FROM base as prod

# Add files
ADD . /var/www
