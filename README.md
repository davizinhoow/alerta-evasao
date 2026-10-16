# Predição de evasão estudantil — código de um sistema em produção

Sistema de machine learning que estima, para cada aluno, a probabilidade de **não se
rematricular no período seguinte**, e entrega uma fila ordenada de risco para a equipe
de retenção trabalhar.

Rodou em produção numa instituição de ensino superior brasileira, cobrindo cinco
modalidades (presencial, EAD, híbrido, pós-graduação e ensino básico) e cerca de
**22 mil alunos**. Este repositório é a parte de código desse sistema, anonimizada.

---

## ⚠️ O que NÃO está aqui, e por quê

Este repositório contém **apenas o código**. Três coisas foram removidas de propósito:

| Removido | Motivo |
|---|---|
| **As queries de extração** (`querys/`) | Definem a fonte de dados e expõem o esquema do ERP acadêmico da instituição |
| **As bases extraídas** (`tabelas/`, `dados/`) | São dados pessoais de estudantes — muitos menores de idade — identificáveis por matrícula |
| **Os modelos treinados** (`.pkl`) | 130 MB ajustados sobre alunos reais; dois deles guardavam listas de matrículas |

Também saíram as suítes de teste e os relatórios executivos, que imprimiam amostras de
alunos reais.

**Consequência prática: o código não roda como está.** Os scripts de `job/` abrem
arquivos `.sql` que não foram publicados, e os notebooks de `ml/` leem CSVs que não
existem aqui. As referências a esses caminhos foram deixadas no código de propósito —
remover daria a impressão de um sistema mais simples do que ele é.

O que dá para ler: a arquitetura, a engenharia de features, o desenho da validação, a
camada de serviço e — principalmente — o raciocínio registrado em comentário.

Nomes de instituição, de produtos (ERP, AVA), de bancos e de pessoas foram substituídos
por genéricos. Matrículas que apareciam como exemplo em documentação viraram números
fictícios na faixa `9xxxxxx`.

---

## O problema, e por que acurácia não serve

A equipe de retenção consegue abordar **cerca de 200 alunos por ciclo**. O modelo não
existe para acertar uma métrica — existe para **ordenar uma fila**. O que importa é
quantos evasores reais estão no topo dela.

Com 12% de evasão, chutar "ninguém evade" dá **88% de acurácia** e não serve para nada.
As métricas que mandam, em ordem:

1. **`recall@200`** — dos evasores reais, quantos caem nos 200 primeiros da fila
2. **`recall@10%`** da base — o mesmo, comparável entre modalidades de tamanhos diferentes
3. **PR-AUC** — qualidade geral do ranking
4. **`precision@K`** — quanto esforço da equipe é desperdiçado

---

## Arquitetura

```
   ERP acadêmico  ─┐
   AVA (telemetria)─┼─► extração SQL ─► notebook ─► modelo ─┐
   financeiro      ─┘   (por coorte)    (treino)            │
                                                            ▼
                      API REST ◄── tabela de risco ◄── job periódico
                         │          (histórico)        (seg e qui)
                         ▼
                   equipe de retenção
```

**Um modelo por modalidade.** Cada uma usa um esquema de código de período diferente
(semestral, trimestral, anual) e toda a aritmética de "período relativo ao ingresso"
depende disso. Misturar quebra o alvo.

**Três cortes por modalidade.** O mesmo aluno é fotografado em momentos distintos do
período — início, meio e fim. Um modelo por corte, porque o que prediz muda: no começo
são sinais de perfil e histórico; no fim, comportamento no ambiente virtual.

---

## As decisões que foram medidas

Nenhuma destas é padrão de livro — todas saíram de medição, e várias contrariaram a
intuição. O detalhe está em [`METODOLOGIA.md`](METODOLOGIA.md).

| Decisão | O que a medição mostrou |
|---|---|
| **Validação em origem deslizante**, nunca split aleatório | Split aleatório superestimava o recall em **38 pontos percentuais** |
| **Janela de observação de 1 período**, não 2 | Com 2 períodos o PR-AUC desabava ao longo das coortes (0,85 → 0,45); com 1 se mantinha |
| **Só publicar modelo que bata uma regra de uma coluna** | Em 2 das 5 modalidades o modelo **empatou** com ordenar por uma única coluna — e não foi publicado |
| **Faixas de risco por posição na fila, não por probabilidade** | Os modelos não são calibrados; o limiar de 0,30 marcava 60% da base |
| **Não imputar média em colunas de período** | O nulo ali é informação: significa que o aluno não cursou nada |

---

## Vazamento: o inimigo principal

A maior parte do esforço do projeto foi caçar vazamento. Alguns casos reais, todos
documentados em `METODOLOGIA.md`:

- Uma feature financeira que lia **pagamento feito depois da data de corte** — o modelo
  sabia o futuro.
- Um `INNER JOIN` com a tabela do AVA que descartava silenciosamente **607 evasores**,
  justamente o grupo de maior risco: quem nunca acessou a plataforma.
- Uma coluna que parecia excelente preditor e era, na verdade, **o registro do próprio
  desfecho** — deixava de existir quando o aluno saía.

> **A regra que mais rendeu:** se uma feature de risco aparece *protegendo* o aluno,
> não é um achado contraintuitivo — é vazamento, e o corte de data está errado.

---

## Estrutura

```
job/     scripts de pontuação periódica — carregam o modelo, montam o X,
         calculam as faixas e gravam a fila. É aqui que a engenharia de
         features de produção precisa bater exatamente com a do treino.

api/     camada de consulta (somente leitura) sobre a tabela de risco.
         Em PHP, pelo ambiente de destino, e uma versão em FastAPI.
         Há quatro variantes porque a unificação aconteceu em paralelo
         ao desenvolvimento por modalidade — a história está nos READMEs.

ml/      notebooks de treino, um por modalidade. Saída de execução apagada.
```

---

## Stack

Python 3.12 · scikit-learn · pandas · LightGBM · CatBoost · SQL Server · PHP · FastAPI

---

## Licença e uso

Código publicado como portfólio técnico. Os dados, as queries de extração e os modelos
treinados pertencem à instituição e não acompanham este repositório.
