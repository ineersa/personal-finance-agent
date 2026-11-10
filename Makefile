.DEFAULT_GOAL := setup

# Only the local demo uses Make; development and QA remain in Castor.
export COMPOSE_PROJECT_NAME HTTP_PORT CHATGPT_CALLBACK_PORT DEMO_ENV_FILE

.PHONY: setup start stop restart help
setup start stop restart help:
	@bash docker/demo.sh $@
