# Mautic Woo Connect

O **Mautic Woo Connect** é uma solução de inteligência de marketing que integra o WooCommerce ao Mautic de forma profunda, indo muito além de uma simples conexão de contatos.

## 🚀 Funcionalidades Principais

* **Rastreamento Comportamental (Client-Side):** Injeção automática do script de rastreamento do Mautic com tagueamento dinâmico baseado em categorias e produtos visualizados.
* **Sincronização RFM (Server-Side):** Cálculo inteligente de **Recência, Frequência e Valor Monetário (LTV)** em tempo real, enviando pontuações (scores de 1 a 5) como campos personalizados para o Mautic.
* **Mapeamento Flexível:** Sincronização de campos personalizados do checkout e do perfil do usuário para o Mautic.
* **Bulk Sync:** Ferramenta de processamento em lote para sincronizar toda a base histórica de pedidos sem sobrecarregar o servidor.
* **LGPD Ready:** Suporte a webhooks para sincronizar o status de "Não Contatar" (Unsubscribe) entre Mautic e WordPress.

## 🛠️ Instalação

1. Baixe a última versão do plugin.
2. No seu WordPress, vá em **Plugins > Adicionar Novo > Enviar Plugin**.
3. Ative o plugin e acesse a aba **Mautic Connect** no menu lateral.
4. Configure sua **URL Base do Mautic** e realize a autenticação via **OAuth 2.0**.

## 🔑 Configuração do Mautic (OAuth 2)

Para a autenticação, crie uma credencial no Mautic:
* **Tipo:** OAuth 2.
* **Redirect URI:** Copie o endereço exibido na tela de configurações do plugin.
* Após salvar no Mautic, insira o `Client ID` e o `Client Secret` no painel do WordPress e clique em "Conectar".

## 🤖 Automações Sugeridas (Mautic)

* **Carrinho Abandonado:** Utilize a tag `carrinho-abandonado` enviada pelo plugin para criar uma campanha de e-mail de recuperação.
* **Limpeza de Funil:** Como o Mautic é aditivo, crie campanhas que removem tags de status antigo (ex: `status:nova-cotacao`) ao adicionar tags de status avançado (ex: `status:cotacao-aprovada`).

## 📋 Requisitos do Servidor

* PHP 7.4 ou superior.
* WooCommerce instalado e ativo.
* Mautic (Self-hosted ou Cloud) com API habilitada.

## 🤝 Contribuição

Este plugin é um projeto da **Prodígito**. Para sugestões de novas funcionalidades ou reportar bugs, utilize a aba *Issues* deste repositório.

---
*Desenvolvido com foco em Performance e Inteligência de Dados.*