/** @type {import('next').NextConfig} */
const nextConfig = {
  output: 'standalone',
  // Le mode strict de React exécute chaque effet deux fois en développement,
  // donc double chaque appel API (la home en faisait 6 au lieu de 3, en file
  // d'attente devant un serveur PHP mono-worker). Sans effet en production,
  // où le mode strict n'est jamais actif.
  reactStrictMode: false,
};

export default nextConfig;
