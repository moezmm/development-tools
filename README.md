# Developper toolkit

Ce programme est un développer toolkit pour Dynacase Platform, il facilite 
l'initialisation et la maintenance d'un projet Dynacase Platform.

Il contient, les fonctionnalités suivantes :

* templates :
    * application : initialisation d'une application,
    * action : initialisation d'une action (enregistrement, droit, layout, code),
    * familles :
       * structure : fichier de structure d'une famille,
       * paramétrage : fichier de paramétrage d'une famille,
       * classe : fichier de code source d'une famille,
    * paquet : structure d'un paquet
* i18n :
    * po : extraction initialisation/mise à jour des po
* package :
    * production du paquet
* stubs :
    * Génération des stubs


L'ensemble des commandes sont mises à disposition par le phar `devtool.phar`

L'ensemble des commandes est :

```
DevTools for Dynacase 3.2
You can access to the sub command :
	createAction
	createApplication
	createFamily
	createInfoXml
	createModule
	createWorkflow
	extractPo
	generateStub
	generateWebinst
```

Les commandes en create permette d'initialiser les fichiers d'un paquet, la commande extract po extrait les po d'un paquet,
la commande generateWebinst produit le webinst et generateStub produit les stubs pour aider au développement.