<script setup>
import backgroundImage from '@/assets/background.jpg';

// 1. Ajouter l'image de fond au body
document.body.style.backgroundImage = `url('${backgroundImage}')`;
document.body.style.backgroundSize = "cover"; // L'image couvre tout l'écran
document.body.style.backgroundPosition = "center"; // Centré
document.body.style.backgroundRepeat = "no-repeat"; // Pas de répétition
document.body.style.margin = "0"; // Supprimer les marges du body
document.body.style.height = "100vh"; // Assurez-vous que le body prend toute la hauteur

// 2. Ajouter un overlay
const overlay = document.createElement('div'); // Crée un div
overlay.style.position = 'fixed'; // Position fixe
overlay.style.top = '0'; // Colle en haut
overlay.style.left = '0'; // Colle à gauche
overlay.style.width = '100vw'; // Largeur égale à l'écran
overlay.style.height = '100vh'; // Hauteur égale à l'écran
overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.18)'; // Noir avec opacité (0.7 = 70%)
overlay.style.pointerEvents = 'none'; // Rend l'overlay "cliquable à travers" si nécessaire

// 3. Ajouter l'overlay au body
document.body.appendChild(overlay);

</script>

<template>

</template>

<style scoped>

</style>
