import { Hammer } from 'lucide-react'
import PaginaEstado from './PaginaEstado'

export default function ProximamentePagina({ seccion }) {
  return (
    <PaginaEstado icono={Hammer} titulo={seccion}>
      <p>Esta sección está habilitada para tu rol y se completará en la siguiente fase del proyecto.</p>
    </PaginaEstado>
  )
}
