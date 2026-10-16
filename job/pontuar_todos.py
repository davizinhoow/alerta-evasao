"""
Runner unico -- pontua as TRES modalidades (presencial + EAD + hibrido) numa so
execucao, para o integrador rodar UM cron no lugar de tres.

    python job/pontuar_todos.py                 # simula os tres (NAO grava)
    python job/pontuar_todos.py --gravar        # grava as tres filas do dia
    python job/pontuar_todos.py --data 2026-10-04 --gravar   # reprocessa um dia nos tres

Roda os tres como subprocessos INDEPENDENTES -- cada um com seu proprio log, seu
proprio modelo e seu proprio exit. Um NAO bloqueia o outro: se o presencial falhar,
o EAD e o hibrido rodam assim mesmo. Os argumentos (--gravar, --data) sao
repassados identicos aos tres.

Codigo de saida:
    0  se os TRES terminaram com exit 0
    1  se QUALQUER um falhou -- o integrador marca o passo como falho

Isto NAO muda nada na pontuacao: e so orquestracao. Cada job continua lendo sua
query, seu .pkl e gravando na sua tabela (TABELA_RISCO_PRESENCIAL / _EAD /
_HIBRIDO). A pasta de deploy junta os tres (job/, querys 09, 11 e 12,
ml/grad-presencial-mensal, grad-ead e grad-hib, .env compartilhado).

O HIBRIDO ENTROU EM 07/10/2026. Ele precisa de DUAS coisas que os outros dois nao
tem na pasta de deploy -- se faltarem, o script quebra ao carregar e sai com 1:
    querys/11_job_hibrido_diario.sql
    ml/grad-hib/modelo_evasao_hibrido_mensal.pkl   (10,5 MB)

E ele NAO e copia dos outros: normaliza CURSO pelos tres ultimos digitos (os pares
2xxx/8xxx do hibrido -- sem isso TODO aluno cai em OUTRO e o modelo nao reconhece
ninguem, em silencio) e grava MOTIVO/MOTIVO2/MOTIVO3. Ver CLAUDE.md.

Cron (integrador), no lugar das linhas antigas:
    30 6 * * 1,4  cd /caminho/evasao-grad && python job/pontuar_todos.py --gravar
"""

import os
import subprocess
import sys

AQUI = os.path.dirname(os.path.abspath(__file__))
JOBS = ["pontuar_presencial.py", "pontuar_ead.py", "pontuar_hibrido.py"]


def main():
    extra = sys.argv[1:]            # --gravar / --data ... repassados aos dois
    resultados = {}
    for script in JOBS:
        caminho = os.path.join(AQUI, script)
        print(f"\n===== {script} {' '.join(extra)} =====", flush=True)
        # mesmo interpretador do runner; stdout/stderr herdados (vao para o log do cron)
        r = subprocess.run([sys.executable, caminho, *extra])
        resultados[script] = r.returncode
        print(f"----- {script}: exit {r.returncode} -----", flush=True)

    print("\n===== resumo =====", flush=True)
    for script, rc in resultados.items():
        print(f"  {script}: {'OK' if rc == 0 else f'FALHOU (exit {rc})'}", flush=True)
    return 0 if all(rc == 0 for rc in resultados.values()) else 1


if __name__ == "__main__":
    sys.exit(main())
