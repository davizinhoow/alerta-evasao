# Metodologia — o que foi medido, e o que isso custou para descobrir

Registro das decisões do projeto. Quase nenhuma é padrão de livro: todas saíram de
medição, e várias contrariaram a intuição de quem as propôs — inclusive a minha.

O formato é sempre o mesmo: **o que se acreditava, o que a medição mostrou, e a regra
que ficou.**

---

## 1. Validação

### Split aleatório superestimava o recall em 38 pontos percentuais

A primeira versão usava `train_test_split` 80/20. O recall na fila de 200 parecia
excelente. Refeito com validação cruzada *out-of-fold*, caiu **38,3 pp**.

O motivo é que um split 80/20 não deixa nota honesta para todo mundo: ao sortear alunos
para inspecionar, 80% deles estavam no treino do modelo que os pontuou. A suíte de
testes estava medindo **memorização**.

> **Regra:** todo aluno recebe a probabilidade de um modelo que **não o viu**. Sem
> exceção, inclusive nos relatórios de demonstração.

### Origem deslizante, nunca split aleatório

É assim que o modelo vai ser usado: treinado no passado, aplicado na turma nova. Cada
origem treina em `coorte <= origem` e testa em `coorte == origem + 1`.

E a leitura certa é a **última origem**, não a média — é ela que estima o que se obtém
na próxima turma.

Isso não é preciosismo. Comparando janela de observação de 2 períodos contra 1:

| | 2 períodos | 1 período |
|---|---|---|
| PR-AUC médio | 0,668 | 0,734 |
| PR-AUC na **última** origem | 0,446 | **0,683** |
| recall@200 na última origem | 51,0% | **67,2%** |

O decisivo foi a **estabilidade**, não a média: com 2 períodos o PR-AUC desabava ao
longo das origens (0,85 → 0,71 → 0,45); com 1 se mantinha (0,74 → 0,77 → 0,68).

> **A armadilha:** nas coortes **antigas** a janela de 2 períodos parecia melhor (83,4%
> contra 74,2% de recall). Quem decidisse olhando histórico escolheria errado. Foi
> exatamente por isso que a validação virou origem deslizante.

---

## 2. O teste que decide se o modelo deve existir

**Compare o modelo com uma regra de uma coluna só.**

Numa das modalidades, ordenar os alunos por "dias desde o último acesso ao ambiente
virtual" — sem modelo nenhum — acertava **exatamente os mesmos evasores** que o
pipeline com vinte features, com o mesmo p-valor.

Nesse caso o modelo não se justifica: a regra é auditável, explicável ao aluno e não
precisa de retreino.

Resultado desse critério aplicado às cinco modalidades: **duas não publicaram.** Não por
falta de desempenho absoluto — uma delas colocava 67% dos evasores numa fila de 15% da
base, 4,5× o acaso — mas porque uma coluna entregava o mesmo.

> **Critério:** só publica modelo que supere a regra de uma coluna por margem que
> sobreviva ao intervalo de confiança.

### Três erros de método cometidos ao aplicar esse teste

**1. Escolher a regra olhando o conjunto de teste.** A primeira comparação pegava a
melhor de seis regras candidatas medida no próprio teste. Isso é seleção no teste, e
favorece a regra. Corrigido, a margem do modelo **aumentou**.

**2. Medir o acaso num conjunto diferente do modelo.** Comparar o modelo medido
out-of-fold (477 alunos) com o acaso simulado sobre a base inteira (776) **invertia a
conclusão**.

**3. Selecionar regra numa base pequena.** Com ~300 evasores, a coorte de validação não
tem tamanho para decidir entre cinco candidatas: a regra vencedora mudou, desabou no
teste, e fez o modelo aparecer com uma margem falsa de +121 evasores.

> Com base pequena, **fixe a regra em vez de selecioná-la**. Um salto grande e
> inesperado na margem é sintoma de troca de regra, não de modelo bom.

---

## 3. Vazamento — o catálogo

A maior parte do esforço do projeto foi caçar vazamento. Estes são casos reais.

### O sintoma mais útil: três cortes entregando o mesmo número

Um modelo com 90 dias de dado **tem que** ser melhor que um com 30. Quando os três
cortes dão o mesmo resultado, há uma feature congelada lendo o futuro.

Foi assim que se descobriu um bloco acadêmico sem filtro de data: as sete colunas saíam
**byte a byte idênticas** entre o corte de 30 e o de 90 dias.

### Sinal invertido é vazamento, não achado contraintuitivo

Uma feature de inadimplência mostrava **4,6% de evasão entre devedores contra 27,0%
entre quem estava em dia**. Parecia um achado interessante sobre perfil de risco.

Era o rastro contábil do cancelamento: quem cancela tem as parcelas futuras estornadas e
termina com saldo zero. O modelo lia a consequência da saída.

Com o corte por data de vencimento, a direção se corrigiu: **21,4% contra 3,3%**.

> **Se uma feature de risco aparece protegendo, procure o corte de data.**

### Pagamento feito depois do corte

A coluna "data da baixa" era `MIN(data_movimentacao)` sem limite superior. Um aluno que
devia no dia do corte e pagou dois meses depois aparecia como adimplente — informação
que não existia no dia em que o modelo seria aplicado.

### O `INNER JOIN` que escondia 607 evasores

A query de uma modalidade fazia `INNER JOIN` com as tabelas do ambiente virtual.
Alunos sem conta na plataforma simplesmente **sumiam da base** — 1.542 deles, dos quais
**607 eram evasores reais**.

O filtro removia sistematicamente o grupo de maior risco da modalidade. Numa modalidade
a distância, não acessar o ambiente virtual é quase a definição de abandono.

| | Antes | Depois |
|---|---|---|
| alunos | 6.543 | **8.085** |
| evasores | 837 | **1.484** |
| taxa de evasão medida | 12,9% | **18,4%** |

> **Sinal de ausência nunca pode ser eliminado por join — tem que virar coluna.**

### Feature que deixa de existir quando o aluno sai

Testaram-se entregas, questionários e matrículas na plataforma como features novas. O
recall saltou de 66,5% para **83,2%**. Era falso.

Separando em grupos:

| Grupo | Alunos | Evasão |
|---|---|---|
| **sem nenhuma atividade atribuída** | 948 | **81,1%** |
| tinha atividade, não fez nenhuma | 1.772 | 13,3% |
| tinha, fez parte | 5.923 | 3,7% |

Quem tinha atividade e não fez evade como a média. **O que prediz é não ter atividade
nenhuma** — e isso acontece porque o aluno já não estava matriculado. É consequência da
saída, não antecedente.

> **Regra geral: feature que deixa de existir quando o aluno sai não é preditor, é
> registro do desfecho.**

### Seleção de feature feita sob vazamento não vale nada

Um notebook afirmava ter "removido 22 variáveis inúteis". Aquele corte tinha sido medido
com o modelo ainda vazado — e contra uma feature que lê o futuro, **qualquer outra
parece inútil**.

Refeito do zero após o conserto, blocos inteiros voltaram a pagar.

> **Corrigiu um vazamento? Refaça o corte de ruído do zero.** A ordem de importância
> muda inteira.

---

## 4. Rótulo pode usar o futuro; população não

A confusão que gerou o defeito mais sutil do projeto.

Um filtro decidia **quem entra na base de treino** usando uma tabela de situação do
aluno que não tem data. Não havia como fatiá-la pela data de corte, então ela descartava
os mesmos ~15% em todos os cortes mensais.

Não era métrica inflada — era base de treino **irreproduzível em produção**. Em outubro,
os alunos que vão concluir em dezembro estão na base, porque ninguém sabe ainda que vão
concluir.

> **O rótulo *é* o que aconteceu depois, e pode olhar o futuro. Quem entra na base tem
> que ser decidido com o que existia na data do corte.**

Tirar o filtro revelou um bug de rótulo que ele escondia: **834 formandos marcados como
evasores**. A regra testava só o período alvo, e o formando some de lá — ele colou grau.

O recall subiu nos cinco cortes.

---

## 5. A métrica que satura sem avisar

Um bloco de features financeiras parecia render **+13 evasores** no topo 200. Antes de
aprovar, uma conferência: **197 dos 200 alunos do topo já tinham cancelado ou se
transferido ANTES da data do corte.**

O modelo não estava prevendo — estava constatando o passado. Dívida é a pegada mais
nítida que a saída deixa, o que torna o bloco financeiro o mais fácil de validar errado.

Excluindo quem já havia encerrado — que é o que o job faz em produção — o ganho caiu
para **+2,4**, dentro do ruído. O bloco não foi para produção.

> **Toda medição tem que rodar sobre a mesma população que a produção vê.**

---

## 6. Medir o que o modelo realmente sabe

Um modelo mostrava recall de **63% entre calouros** contra 16% entre veteranos. Parecia
que ele era especialmente bom com alunos novos.

Não era. Ele fazia outra coisa: **empurrava o grupo inteiro para cima**. Metade dos
calouros entrava na fila, contra 4% dos veteranos. Como calouro evade 29% e veterano
9,5%, colocar o grupo todo na frente já rende recall alto — sem precisar escolher
**quem**, dentro do grupo.

**O teste que isola isso: montar a fila só entre calouros.** Aí o atalho some.

| | Fila só entre calouros | Fila só entre veteranos |
|---|---|---|
| ganho sobre o acaso | **1,60×** | 3,12× |

Dentro do grupo, o modelo é quase o dobro pior — e faz sentido, porque 4 das 10 features
mais importantes vinham vazias para **todo mundo** ali.

> **Recall alto num subgrupo pode significar "o modelo priorizou o grupo", não "o modelo
> sabe quem, dentro do grupo". Para separar, monte a fila dentro do subgrupo.**

Consequência prática: o modelo não foi ligado para turmas de primeiro semestre, onde
quase todos são calouros e esse mecanismo desaparece.

---

## 7. Decisão medida numa modalidade não vale na outra

Remover o bloco de features do ambiente virtual dos cortes precoces rendeu **+41 e +74
evasores** numa modalidade. A tentação era herdar a decisão nas outras.

Medido na modalidade seguinte: valeu em **um** dos três cortes, e por pouco. A premissa
original era a cobertura irregular do log; na outra modalidade a cobertura era de 92% e
a ambiguidade quase não existia.

> **O notebook passou a trazer a medição embutida**, para que ela se refaça sozinha a
> cada coorte nova em vez de virar número herdado.

### E ganho em modelo de teste não transfere para o pipeline real

Uma mudança rendia **+15 e +58** medida num RandomForest cru sobre todas as colunas. No
pipeline de verdade — com corte de features, engenharia e seleção — virou **+0,3 e +3,2
pontos percentuais**.

O modelo cru tira proveito de qualquer sinal; o pipeline já compensava parte daquilo por
outro caminho.

> **Meça a mudança no modelo de verdade, nunca num modelo de teste.**

---

## 8. Controles que evitaram conclusões erradas

### Antes de culpar uma variável, remova cada alternativa

Suspeitava-se que uma coorte específica contaminava o treino por cair num mês de
recesso. Remover essa coorte, de fato, **melhorava**.

O controle: remover **cada uma** das outras coortes, uma a uma. Remover *qualquer* uma
também melhorava — sinal de que o efeito era de composição da amostra, não do calendário.

Sem esse controle, teria ficado registrado "o mês de dezembro carrega sinal ruim", que é
falso.

### Conferir conteúdo, não forma

O sistema gera explicações em texto para cada aluno da fila ("aprovou 40% das
disciplinas do período anterior"). Um teste de formato não pegaria o pior defeito
possível: se os textos se desalinhassem dos alunos na ordenação, **todos** ficariam
errados e plausíveis ao mesmo tempo.

O teste certo foi extrair o número de dentro de cada frase gerada e comparar com a
coluna crua, reextraída do banco: **781 pares, zero divergência**.

Esse processo encontrou dois erros reais de texto que a revisão visual tinha deixado
passar.

---

## 9. Regras de modelagem herdadas

- **Não imputar média em colunas de período.** Ali o nulo é informação: significa que o
  aluno não cursou nada naquele período — quase o próprio sinal de evasão. LightGBM,
  CatBoost e o RandomForest do scikit-learn ≥ 1.4 tratam `NaN` nativamente.
- **Códigos são categorias, não números.** Código de curso e de município precisam de
  tratamento categórico, com a cauda longa agrupada.
- **Base desbalanceada** (~10–16% de evasão): `class_weight` / `scale_pos_weight`.
- **Gênero e raça não entram no `X` de produção.** Usar como feature preditiva direciona
  intervenção por gênero e raça. Entram apenas na **auditoria de viés** — que, aliás,
  encontrou um desvio de 10,3 pp que sobreviveu à remoção da coluna, porque entrava por
  proxy através do código do curso.
- **Faixas de risco por posição na fila, não por probabilidade.** Os modelos não são
  calibrados; um limiar de 0,30 marcava 60% da base numa modalidade e quase ninguém em
  outra.

---

## 10. O que o dado não responde

Depois de todos os consertos, os evasores que escapam da fila continuam
**indistinguíveis** de quem permanece em tudo que os sistemas registram: nota 6,1 contra
6,5, mesmo número de disciplinas, mesma atividade no ambiente virtual.

O motivo da saída deles está fora da instituição.

> Antes de procurar mais uma coluna, vale perguntar à equipe de retenção o que os alunos
> dizem quando saem. Nem todo problema de predição é um problema de modelagem.
