# Changelog - FC DME Solver

## Version 1.1.0 - MODAL DE DESAFIOS RENOVADO 🎮

### ✨ Novo Design do Modal
1. **Layout em Grid**
   - ✅ 3 colunas em desktop
   - ✅ 2 colunas em mobile
   - ✅ Design moderno e limpo

2. **Cards Simplificados**
   - ✅ Removidos requisitos e descrição
   - ✅ Apenas: Imagem + Nome + Preço
   - ✅ Estilo visual inspirado nas referências

3. **Melhorias no Cabeçalho**
   - ✅ Contador de desafios
   - ✅ Total calculado automaticamente
   - ✅ Meta informações organizadas

### 🎨 Estilo Visual
- Cards com fundo escuro (#14192380)
- Bordas sutis e hover effects
- Imagens centralizadas em aspect ratio 1:1
- Preço em verde (#4ade80)
- Layout responsivo otimizado

### 📱 Responsividade
- Desktop: Grid 3 colunas
- Tablet: Grid 2 colunas  
- Mobile: Grid 2 colunas (otimizado)

---

## Version 1.0.1 - CORREÇÕES IMPORTANTES

### ✅ Corrigido
1. **Renderização de Jogadores**
   - Removido fallback simplificado
   - Agora usa SEMPRE o FC Card Renderer (igual ao plugin de referência)
   - Exibe erro claro se o renderer não estiver instalado

2. **Imagens dos Desafios**
   - Adicionadas imagens dos challenges no modal
   - Layout melhorado com imagem à esquerda + info à direita
   - Responsivo: em mobile a imagem fica acima

3. **Toggle Console/PC como Regra Universal**
   - O toggle agora controla TODOS os preços (cards + desafios)
   - Não duplica mais preços no popup
   - Mostra apenas o preço da plataforma selecionada

---

## Version 1.0.0 - Lançamento Inicial

- Sistema de toggle Console/PC
- Modal de desafios
- Integração com FC Card Renderer
- Cache configurável
