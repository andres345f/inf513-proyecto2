import { PackageOpen } from 'lucide-react'
import './EstadoVacio.css'

/**
 * Estado vacío útil para listados y paneles: explica qué significa y
 * ofrece la acción de desbloqueo. «accion» es un nodo (normalmente un Boton).
 */
export default function EstadoVacio({ titulo, mensaje, accion, icono: IconoVacio = PackageOpen }) {
  return (
    <div className="estado-vacio">
      <span className="estado-vacio__icono" aria-hidden="true"><IconoVacio size={34} /></span>
      <h3 className="estado-vacio__titulo">{titulo}</h3>
      {mensaje && <p className="estado-vacio__mensaje">{mensaje}</p>}
      {accion && <div className="estado-vacio__accion">{accion}</div>}
    </div>
  )
}
