# Git Commands Cheat Sheet

## First time setup
Kapag bagong project at ikokonekta pa lang sa GitHub:

```bash
git init
git remote add origin https://github.com/USERNAME/REPOSITORY.git
git branch -M main
git push -u origin main

Normal update / push
Kapag may binago sa code:

git add .
git commit -m "Describe your changes"
git push

Example: Update login page
Kapag isang page lang ang binago:

git add login.php
git commit -m "Fix login bug"
git push

Check changes bago mag-commit
Para makita kung anong files ang nabago:

git status

Tingnan ang connected GitHub repository
Para malaman kung anong GitHub repo ang naka-link:

git remote -v

Kapag may bagong changes mula GitHub
Para kunin ang latest changes mula sa GitHub papunta sa local project:

git pull origin main

Kapag gusto i-upload ang specific file lang
Halimbawa isang file lang ang binago:

git add filename.php
git commit -m "Update filename"
git push

Kapag gusto i-check ang commit history
Para makita ang mga dating ginawa na commits:

git log

