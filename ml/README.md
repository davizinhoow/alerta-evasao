# Diretório de Modelos de Machine Learning (`ml/`)

Este diretório contém os **Jupyter Notebooks interativos** com os pipelines completos de Machine Learning para previsão de evasão de alunos do a instituicao, segregados por modalidade de ensino.

---

## 🤖 1. Notebooks Disponíveis e Resultados Consolidados

> ⚠️ **Os números não são comparáveis entre as linhas.** O híbrido é o único validado em
> **split temporal** (treina em coortes passadas, testa na turma seguinte); os demais usam
> split aleatório dentro de uma coorte, que infla a métrica num problema temporal. E o
> **O presencial consolidado foi removido em 23/09/2026.** Ele era o corte de 31/12 —
> exatamente o `m5` de `grad-presencial-mensal/`, que faz a mesma coisa validado por
> CV out-of-fold e com a base já corrigida. Use o mensal.

| Notebook | Modalidade | Base Elegível | Evasores Reais | Taxa de Evasão | ROC-AUC | Recall Otimizado (Sensibilidade) | Evasores Capturados no Teste |
|---|---|---|---|---|---|---|---|
| **`grad-presencial-mensal/`** | Presencial (`D`, `N`) — 5 modelos, um por mês | 9.079 | 1.095 | **12,06%** | **0.851** | **`91,3%`** *(Thresh 0.30)*<br>**`17,8%`** *(recall@200)* | **195 / 1.095** *(topo 200, corte de 31/12)* |
| **`pos-live-mensal/`** | Pós Live (`EJND_POS_GRAD`) — 3 modelos, um por marco | 428 | 122 | **28,5%** | **0.815** | **`86,1%`** *(Thresh 0.30)*<br>**`32,8%`** *(recall@50)* | **40 / 122** *(topo 50, marco de 90 dias)* |
| **`modelo_evasao_ead.ipynb`** | EAD (`EAD`) | 6.242 | 583 | **9,34%** | **0.7862** | **`84,62%`** *(Thresh 0.40)*<br>**`96,58%`** *(Thresh 0.35)* | **99 / 117** *(0.40)*<br>**113 / 117** *(0.35)* |
| **`modelo_evasao_hibrido.ipynb`** | Híbrido (`HIB`) | 6.785 *(3 coortes)* | 582 | **8,58%** | **0.9047** | **`90,17%`** *(Thresh 0.30)*<br>**`54,91%`** *(Recall@200)* | **156 / 173** *(0.30)*<br>**95 / 173** *(Top 200)* |
| **`modelo_evasao_ext.ipynb`** | Aperfeiçoamento / Extensão (`EJND_EXT`) | 1.672 | 102 | **6,10%** | **0.8230** | **`90,00%`** *(Thresh 0.30)*<br>**`95,00%`** *(Thresh 0.25)* | **18 / 20** *(0.30)*<br>**19 / 20** *(0.25)* |
| **`pos-ead/modelo_evasao_pos_ead.ipynb`** | Pós-Graduação EAD (`EAD_POS_GRAD`) | 776 | 34 | **4,38%** | **0.650** *(out-of-fold)* | **`59,1%`** *(Thresh 0.60)*<br>**`27,3%`** *(recall@10%)* | **13 / 22** *(0.60)*<br>**6 / 22** *(topo 10%)* |

> ### ⛔ A pós EAD não tem modelo publicável — e isso é o resultado, não uma pendência
>
> É a **única linha da tabela medida honestamente**: origem deslizante mensal, métrica
> out-of-fold, limiar escolhido em validação. Justamente por isso os números são os mais
> baixos — e os únicos diretamente comparáveis ao acaso.
>
> Há sinal real: o modelo entrega `recall@10%` de 27,3% contra 10,1% do acaso, com
> p-valor empírico de 0,017. **Mas ordenar por `AVA_DIAS_ATIVOS` — uma coluna, sem modelo
> — acerta exatamente os mesmos 6 evasores de 22.** Vinte features e um pipeline para
> entregar o que uma linha de SQL entrega. Um modelo só se justifica quando supera a
> heurística que pretende substituir; este não supera. Gargalo é dado: 1,7 evento por
> variável, e a métrica oscila de 0,0% a 100% entre origens com 2–4 evasores cada.
>
> **Recomendação vigente:** regra simples "sem acesso ao AVA nos primeiros 30 dias" (8,6%
> de evasão contra 3,8%), coleta de desfecho das abordagens e remedição em março/2027.
> Ver `relatorios/relatorio-geral/Relatorio_Pesquisa_Previsao_Evasao_Pos_EAD_a instituicao.pdf`.
>
> A versão anterior deste notebook reportava ROC-AUC de **0,948** — era vazamento
> (sem `DT_CORTE`, alvo tirado da situação cadastral atual, 62% da base ainda em curso
> contada como não-evasão). Nenhum daqueles números vale.

---

## 📦 Artefatos Exportados (.pkl / Pickle)
- **`ml/grad-presencial-mensal/modelo_evasao_presencial_mensal.pkl`**: os **5 modelos** do alerta mensal (um por mês letivo), medianas de imputação por corte, municípios agrupados, as probabilidades **out-of-fold** de cada aluno e a política de fila.
- **`ml/pos-live-mensal/modelo_evasao_pos_live_mensal.pkl`**: os **3 modelos** por marco de dias, com `colunas_modelo` (o que o `predict` aceita), `agrupamentos` do one-hot e probabilidades out-of-fold.
- **`ml/grad-ead/modelo_evasao_ead_mensal.pkl`**: os **3 modelos** do alerta do EAD (um por corte do trimestre), com `colunas_modelo`, `agrupamentos` do one-hot e as probabilidades da coorte de teste. Treinado em **4 coortes** (2025/73 a 2026/72) e validado em **2 origens deslizantes** (01/10/2026) — os três cortes superam a regra de uma coluna nas duas.
  > O antigo `ml/grad-ead/modelo_evasao_ead.pkl` (corte único) foi **apagado em 01/10/2026**: era anterior aos consertos da query 02 e treinou sem 607 dos evasores reais. As métricas que este README anunciava para ele (recall 84,62%, captura 93,0%) vinham de avaliação sobre a própria base de treino — mediam memorização, não acerto.
- **`ml/extensao/modelo_evasao_extensao.pkl`**: Modelo Random Forest treinado para Aperfeiçoamento/Extensão, calibrado e fixado em **`0.30`** (Recall: **90,00%** / Captura Teste: **18/20**), com pipeline de imputação, features financeiras e demográficas.

---

## ⚙️ 2. Estrutura Padrão dos Notebooks (12 Etapas Modulares)

Cada notebook foi construído com células modulares, organizadas e documentadas:

1. **Imports & Configurações:** `pandas`, `numpy`, `scikit-learn`, `matplotlib`, `seaborn`.
2. **Carregamento dos Dados:** Leitura direta da pasta `tabelas/`.
3. **Filtro Mandatório de Elegibilidade (`ELEGIVEL == 1`):** Elimina censura à direita e saídas prévias ao período alvo.
4. **Engenharia de Features de Risco Combinado:**
   - `RISCO_NOTA_BAIXA`: Nota média $< 5.0$.
   - `RISCO_FREQ_BAIXA`: Presença média $< 75\%$.
   - `RISCO_SUMIU_AVA`: Sem login no AVA há mais de 30 dias.
   - `RISCO_COMBO_ACAD_AVA`: Nota baixa **E** sumido do AVA (colapso acadêmico-digital).
   - `RATIO_GAP_ATIVOS`: Proporção do maior buraco sem login dividido pelos dias ativos.
   - `SESSOES_POR_DIA_ATIVO`: Frequência diária de acessos.
   - `DESACELEROU_AVA`: Queda de $>50\%$ no ritmo recente de sessões vs média do período.
   - `DISC_REPROVA_CRITICA`: Mais de $25\%$ das matérias com reprovação por falta.
5. **Separação de $X$ e $y$:** Exclusão rigorosa de vazamentos (`CURSOU_ALVO`, `SIT_ALVO`, `DT_CORTE`, `ALUNO`, etc.).
6. **Encoding & Limpeza de Nulos:** One-Hot Encoding e descarte de colunas 100% nulas.
7. **Divisão Estratificada (80% Treino / 20% Teste):** Preserva a proporção exata da classe minoritária.
8. **Treinamento com Random Forest Ponderado:** `RandomForestClassifier` com `class_weight` penalizando a perda de evasores.
9. **Tabela de Otimização do Limiar de Decisão (*Threshold Tuning*):** Comparação passo a passo dos cortes de probabilidade ($0.50 \rightarrow 0.20$).
10. **Métricas de Ação Operacional (Top 200 da Fila):** `Recall@200` e `Precision@200` para a capacidade de abordagem da equipe.
11. **Matrizes de Confusão Comparativas:** Visualização gráfica lado a lado (Corte Padrão 0.50 vs Corte Otimizado 0.30).
12. **Curva ROC, Curva Precision-Recall e Feature Importances:** Ranking das 20 variáveis com maior poder preditivo.

---

## 🎯 3. Estratégia de Abordagem Operacional em 2 Camadas

Para operacionalizar as predições geradas pelos modelos com a equipe de **4 pessoas + Bot do Blip no WhatsApp**.

**No presencial, as faixas saem da POSIÇÃO na fila do dia, não da probabilidade**
(revisto em 24/09/2026). O modelo não é calibrado: com o `.pkl` atual, 58% da base
passa de 0,30, então um corte por probabilidade marcaria metade da turma. O job diário
(`job/pontuar_presencial.py`) ordena todo mundo e grava só as três faixas em
`BANCO_APP.dbo.TABELA_RISCO_PRESENCIAL`:

```
              [ Fila do dia: todos os alunos, do maior risco ao menor ]
                                        │
      ┌─────────────────────────────────┼─────────────────────────────────┐
      ▼                                 ▼                                 ▼
 🔴 CRITICA                        🟠 ALTA                           🟡 MEDIA
 20% do topo da ALTA               resto dos 10% do topo             de 10% a 20% da base
 ≈ 2% da base (~189)               (~754)                            (~943)
 ~41% evadem                       ~25% evadem                       ~16% evadem
      │                                 │                                 │
      ▼                                 ▼                                 ▼
 👨‍💼 4 Atendentes Humanos          🤖 Bot do Blip (WhatsApp)         acompanhamento
 • Ligação individual              • Disparo ativo de acolhimento    (ação a definir)
 • Resgate acadêmico               • Oferta de monitoria / suporte
 • Contato da coordenação          • Transbordo humano se responder

          os outros ~80% não são gravados: ausência na tabela = sem risco
```

*Tamanhos com a base de 23/09/2026 (9.429 alunos). "% que evadem" = média dos 5
meses, CV out-of-fold na coorte 2025/2; a média geral da base é 8,2%. Detalhe em
`CLAUDE.md`, item do job diário.*

---

## 🚀 4. Como Executar os Notebooks

1. Abra o arquivo desejado no **VSCode** ou **Jupyter Lab**.
2. No canto superior direito, selecione o Kernel do ambiente virtual:
   👉 **`Python (ml-evasao-alunos-v3)`** ou aponte para `.venv/Scripts/python.exe`.
3. Clique em **Run All** para re-executar todas as células.
