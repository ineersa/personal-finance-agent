<p align="center"><img src="docs/images/pf-logo.svg" width="80" alt="Personal finance"></p>

# Personal finance

Upload CSV bank statements, review your transactions, and ask questions about your finances—with answers and interactive charts in one chat.

Built for the Symfony AI hackathon. Powered by [Symfony](https://symfony.com/), [Symfony AI](https://symfony.com/ai), [EasyAdmin](https://github.com/EasyCorp/EasyAdminBundle) and our [ChatGPT provider for Symfony AI](https://github.com/ineersa/symfony-ai-openai-chatgpt-platform).

<p align="center">
  <a href="https://youtu.be/nbKGmY-PTqE">
    <img src="https://img.youtube.com/vi/nbKGmY-PTqE/hqdefault.jpg" width="640" alt="Watch the demo on YouTube">
  </a>
</p>

## Run the demo

You'll need:
- Docker with Compose **2.24.4+** and Bash.
- A **Decision API key**, such as [Jev](https://typesafe.ai/), for statement processing.
- A **ChatGPT account** for chat.

```bash
git clone https://github.com/ineersa/personal-finance-agent.git
cd personal-finance-agent
./setup-demo.sh
```

Enter your Decision API key when prompted. The first setup takes a few minutes and includes sample financial data.

Open **http://127.0.0.1:8080/** and log in with **demo@test.com / demo**. Connect ChatGPT in **Settings** to start chatting.

Jev is the default Decision API. To use another compatible provider, change `JEV_BASE_URL`, `JEV_MODEL` and `JEV_API_KEY` in the private `.env.prod.local` file.

Run `./setup-demo.sh` again to restart or update the demo; your data and settings are kept. The script also prints the shutdown command.

**Use sample data only and keep the demo local.** Statement content is sent to your Decision API provider; chat context is sent to ChatGPT.

## Built with Hatfield

All development was done with [Hatfield](https://github.com/ineersa/agent-core), our coding agent powered by [Symfony AI](https://symfony.com/ai).

## Learn more

[Architecture and diagrams](ARCHITECTURE.md) · [Development and testing](TESTING.md)
